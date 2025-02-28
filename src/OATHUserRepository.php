<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

namespace MediaWiki\Extension\OATHAuth;

use BagOStuff;
use ConfigException;
use FormatJson;
use InvalidArgumentException;
use MediaWiki\Extension\OATHAuth\Notifications\Manager;
use MediaWiki\User\CentralId\CentralIdLookupFactory;
use MWException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use RequestContext;
use RuntimeException;
use User;
use Wikimedia\Rdbms\IConnectionProvider;

class OATHUserRepository implements LoggerAwareInterface {
	private IConnectionProvider $dbProvider;

	private BagOStuff $cache;

	private OATHAuthModuleRegistry $moduleRegistry;

	private CentralIdLookupFactory $centralIdLookupFactory;

	private LoggerInterface $logger;

	public function __construct(
		IConnectionProvider $dbProvider,
		BagOStuff $cache,
		OATHAuthModuleRegistry $moduleRegistry,
		CentralIdLookupFactory $centralIdLookupFactory,
		LoggerInterface $logger
	) {
		$this->dbProvider = $dbProvider;
		$this->cache = $cache;
		$this->moduleRegistry = $moduleRegistry;
		$this->centralIdLookupFactory = $centralIdLookupFactory;
		$this->setLogger( $logger );
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @param User $user
	 * @return OATHUser
	 * @throws ConfigException
	 * @throws MWException
	 */
	public function findByUser( User $user ) {
		$oathUser = $this->cache->get( $user->getName() );
		if ( !$oathUser ) {
			$oathUser = new OATHUser( $user, [] );

			$uid = $this->centralIdLookupFactory->getLookup()
				->centralIdFromLocalUser( $user );

			$res = $this->dbProvider
				->getReplicaDatabase( 'virtual-oathauth' )
				->newSelectQueryBuilder()
				->select( [
					'oad_data',
					'oat_name',
				] )
				->from( 'oathauth_devices' )
				->join( 'oathauth_types', null, [ 'oat_id = oad_type' ] )
				->where( [ 'oad_user' => $uid ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			$module = null;

			foreach ( $res as $row ) {
				if ( $module && $row->oat_name !== $module->getName() ) {
					// Not supported by current application-layer code.
					throw new RuntimeException( "user {$uid} has multiple different oathauth modules defined" );
				}

				if ( !$module ) {
					$module = $this->moduleRegistry->getModuleByKey( $row->oat_name );
					$oathUser->setModule( $module );

					if ( !$module ) {
						throw new MWException( 'oathauth-module-invalid' );
					}
				}

				$keyData = FormatJson::decode( $row->oad_data, true );
				$oathUser->addKey( $module->newKey( $keyData ) );
			}

			$this->cache->set( $user->getName(), $oathUser );
		}
		return $oathUser;
	}

	/**
	 * @param OATHUser $user
	 * @param string|null $clientInfo
	 * @throws ConfigException
	 * @throws MWException
	 */
	public function persist( OATHUser $user, $clientInfo = null ) {
		if ( !$clientInfo ) {
			$clientInfo = RequestContext::getMain()->getRequest()->getIP();
		}
		$prevUser = $this->findByUser( $user->getUser() );
		$userId = $this->centralIdLookupFactory->getLookup()->centralIdFromLocalUser( $user->getUser() );

		$moduleId = $this->moduleRegistry->getModuleId( $user->getModule()->getName() );

		$dbw = $this->dbProvider->getPrimaryDatabase( 'virtual-oathauth' );
		$dbw->startAtomic( __METHOD__ );

		// TODO: only update changed rows
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'oathauth_devices' )
			->where( [ 'oad_user' => $userId ] )
			->caller( __METHOD__ )
			->execute();

		$insert = $dbw->newInsertQueryBuilder()
			->insertInto( 'oathauth_devices' )
			->caller( __METHOD__ );
		foreach ( $user->getKeys() as $key ) {
			$insert->row( [
				'oad_user' => $userId,
				'oad_type' => $moduleId,
				'oad_data' => FormatJson::encode( $key->jsonSerialize() )
			] );
		}
		$insert->execute();

		$dbw->endAtomic( __METHOD__ );

		$userName = $user->getUser()->getName();
		$this->cache->set( $userName, $user );

		if ( $prevUser !== false ) {
			$this->logger->info( 'OATHAuth updated for {user} from {clientip}', [
				'user' => $userName,
				'clientip' => $clientInfo,
				'oldoathtype' => $prevUser->getModule()->getName(),
				'newoathtype' => $user->getModule()->getName(),
			] );
		} else {
			// If findByUser() has returned false, there was no user row or cache entry
			$this->logger->info( 'OATHAuth enabled for {user} from {clientip}', [
				'user' => $userName,
				'clientip' => $clientInfo,
				'oathtype' => $user->getModule()->getName(),
			] );
			Manager::notifyEnabled( $user );
		}
	}

	/**
	 * Persists the given OAuth key in the database.
	 *
	 * @param OATHUser $user
	 * @param IModule $module
	 * @param array $keyData
	 * @param string $clientInfo
	 * @return IAuthKey
	 */
	public function createKey( OATHUser $user, IModule $module, array $keyData, string $clientInfo ): IAuthKey {
		if ( $user->getModule() && $user->getModule()->getName() !== $module->getName() ) {
			throw new InvalidArgumentException(
				"User already has a key from a different module enabled ({$user->getModule()->getName()})"
			);
		}

		$userId = $this->centralIdLookupFactory->getLookup()->centralIdFromLocalUser( $user->getUser() );
		$moduleId = $this->moduleRegistry->getModuleId( $module->getName() );

		$dbw = $this->dbProvider->getPrimaryDatabase( 'virtual-oathauth' );
		$dbw->newInsertQueryBuilder()
			->insertInto( 'oathauth_devices' )
			->row( [
				'oad_user' => $userId,
				'oad_type' => $moduleId,
				'oad_data' => FormatJson::encode( $keyData ),
			] )
			->caller( __METHOD__ )
			->execute();
		$id = $dbw->insertId();

		$hasExistingKey = $user->isTwoFactorAuthEnabled();

		$key = $module->newKey( $keyData );
		$user->addKey( $key );

		$this->logger->info( 'OATHAuth {oathtype} key {key} added for {user} from {clientip}', [
			'key' => $id,
			'user' => $user->getUser()->getName(),
			'clientip' => $clientInfo,
			'oathtype' => $module->getName(),
		] );

		if ( !$hasExistingKey ) {
			$user->setModule( $module );
			Manager::notifyEnabled( $user );
		}

		return $key;
	}

	/**
	 * @param OATHUser $user
	 * @param string $clientInfo
	 * @param bool $self Whether the user disabled the 2FA themselves
	 */
	public function remove( OATHUser $user, $clientInfo, bool $self ) {
		$userId = $this->centralIdLookupFactory->getLookup()
			->centralIdFromLocalUser( $user->getUser() );
		$this->dbProvider->getPrimaryDatabase( 'virtual-oathauth' )
			->newDeleteQueryBuilder()
			->deleteFrom( 'oathauth_devices' )
			->where( [ 'oad_user' => $userId ] )
			->caller( __METHOD__ )
			->execute();

		$userName = $user->getUser()->getName();
		$this->cache->delete( $userName );

		$this->logger->info( 'OATHAuth disabled for {user} from {clientip}', [
			'user' => $userName,
			'clientip' => $clientInfo,
			'oathtype' => $user->getModule()->getName(),
		] );
		Manager::notifyDisabled( $user, $self );
	}
}
