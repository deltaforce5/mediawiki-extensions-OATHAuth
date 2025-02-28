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

use InvalidArgumentException;
use ReflectionClass;
use User;

/**
 * Class representing a user from OATH's perspective
 *
 * @ingroup Extensions
 */
class OATHUser {
	private User $user;

	/** @var IAuthKey[] */
	private $keys;

	/**
	 * @var ?IModule
	 */
	private $module;

	/**
	 * Constructor. Can't be called directly. Use OATHUserRepository::findByUser instead.
	 * @param User $user
	 * @param IAuthKey[] $keys
	 */
	public function __construct( User $user, array $keys = [] ) {
		$this->user = $user;
		$this->setKeys( $keys );
	}

	/**
	 * @return User
	 */
	public function getUser() {
		return $this->user;
	}

	/**
	 * @return string
	 */
	public function getIssuer() {
		global $wgSitename, $wgOATHAuthAccountPrefix;

		if ( $wgOATHAuthAccountPrefix !== false ) {
			return $wgOATHAuthAccountPrefix;
		}
		return $wgSitename;
	}

	/**
	 * @return string
	 */
	public function getAccount() {
		return $this->user->getName();
	}

	/**
	 * Get the key associated with this user.
	 *
	 * @return IAuthKey[]|array
	 */
	public function getKeys() {
		return $this->keys;
	}

	/**
	 * Useful for modules that operate on single-key premise,
	 * as well as testing the key type, since the first key is(?)
	 * necessarily the same type as others
	 *
	 * @return IAuthKey|null
	 */
	public function getFirstKey() {
		return $this->keys[0] ?? null;
	}

	/**
	 * Set the key associated with this user.
	 *
	 * @param IAuthKey[] $keys
	 */
	public function setKeys( array $keys = [] ) {
		$this->keys = [];
		foreach ( $keys as $key ) {
			$this->addKey( $key );
		}
	}

	/**
	 * Removes all keys associated with the user
	 * Warning: This only removes the keys in memory,
	 * changes need to be persisted
	 */
	public function clearAllKeys() {
		$this->keys = [];
	}

	/**
	 * Adds single key to the key array
	 *
	 * @param IAuthKey $key
	 */
	public function addKey( IAuthKey $key ) {
		$this->checkKeyTypeCorrect( $key );
		$this->keys[] = $key;
	}

	/**
	 * Gets the module instance associated with this user
	 *
	 * @return IModule|null
	 */
	public function getModule() {
		return $this->module;
	}

	/**
	 * Sets the module instance associated with this user
	 *
	 * @param IModule|null $module
	 */
	public function setModule( IModule $module = null ) {
		$this->module = $module;
	}

	/**
	 * @return bool Whether this user has two-factor authentication enabled or not
	 */
	public function isTwoFactorAuthEnabled(): bool {
		return count( $this->getKeys() ) >= 1;
	}

	/**
	 * Disables current (if any) auth method
	 */
	public function disable() {
		$this->keys = [];
		$this->module = null;
	}

	/**
	 * All keys set for the user must be of the same type
	 * @param IAuthKey $key
	 */
	private function checkKeyTypeCorrect( IAuthKey $key ): void {
		$newKeyClass = get_class( $key );
		foreach ( $this->keys as $keyToTest ) {
			if ( get_class( $keyToTest ) !== $newKeyClass ) {
				$first = ( new ReflectionClass( $keyToTest ) )->getShortName();
				$second = ( new ReflectionClass( $key ) )->getShortName();

				throw new InvalidArgumentException(
					"User already has a key from a different two-factor module enabled ($first !== $second)"
				);
			}
		}
	}
}
