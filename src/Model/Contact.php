<?php
/**
 * @copyright Copyright (C) 2010-2022, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Friendica\Model;

use Friendica\App\BaseURL;
use Friendica\Contact\Introduction\Exception\IntroductionNotFoundException;
use Friendica\Content\Pager;
use Friendica\Content\Text\HTML;
use Friendica\Core\Hook;
use Friendica\Core\Logger;
use Friendica\Core\Protocol;
use Friendica\Core\Renderer;
use Friendica\Core\Session;
use Friendica\Core\System;
use Friendica\Core\Worker;
use Friendica\Database\Database;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Network\HTTPException;
use Friendica\Network\Probe;
use Friendica\Protocol\Activity;
use Friendica\Protocol\ActivityPub;
use Friendica\Protocol\Diaspora;
use Friendica\Protocol\OStatus;
use Friendica\Protocol\Salmon;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Images;
use Friendica\Util\Network;
use Friendica\Util\Proxy;
use Friendica\Util\Strings;

/**
 * functions for interacting with a contact
 */
class Contact
{
	const DEFAULT_AVATAR_PHOTO = '/images/person-300.jpg';
	const DEFAULT_AVATAR_THUMB = '/images/person-80.jpg';
	const DEFAULT_AVATAR_MICRO = '/images/person-48.jpg';

	/**
	 * @}
	 */

	const LOCK_INSERT = 'contact-insert';

	/**
	 * Account types
	 *
	 * TYPE_UNKNOWN - unknown type
	 *
	 * TYPE_PERSON - the account belongs to a person
	 *	Associated page types: PAGE_NORMAL, PAGE_SOAPBOX, PAGE_FREELOVE
	 *
	 * TYPE_ORGANISATION - the account belongs to an organisation
	 *	Associated page type: PAGE_SOAPBOX
	 *
	 * TYPE_NEWS - the account is a news reflector
	 *	Associated page type: PAGE_SOAPBOX
	 *
	 * TYPE_COMMUNITY - the account is community forum
	 *	Associated page types: PAGE_COMMUNITY, PAGE_PRVGROUP
	 *
	 * TYPE_RELAY - the account is a relay
	 *      This will only be assigned to contacts, not to user accounts
	 * @{
	 */
	const TYPE_UNKNOWN =     -1;
	const TYPE_PERSON =       User::ACCOUNT_TYPE_PERSON;
	const TYPE_ORGANISATION = User::ACCOUNT_TYPE_ORGANISATION;
	const TYPE_NEWS =         User::ACCOUNT_TYPE_NEWS;
	const TYPE_COMMUNITY =    User::ACCOUNT_TYPE_COMMUNITY;
	const TYPE_RELAY =        User::ACCOUNT_TYPE_RELAY;
	/**
	 * @}
	 */

	/**
	 * Contact_is
	 *
	 * Relationship types
	 * @{
	 */
	const NOTHING  = 0;
	const FOLLOWER = 1;
	const SHARING  = 2;
	const FRIEND   = 3;
	const SELF     = 4;
	/**
	 * @}
	 */

	 const MIRROR_DEACTIVATED = 0;
	 const MIRROR_FORWARDED = 1;
	 const MIRROR_OWN_POST = 2;
	 const MIRROR_NATIVE_RESHARE = 3;

	 /**
	 * @param array $fields    Array of selected fields, empty for all
	 * @param array $condition Array of fields for condition
	 * @param array $params    Array of several parameters
	 * @return array
	 * @throws \Exception
	 */
	public static function selectToArray(array $fields = [], array $condition = [], array $params = [])
	{
		return DBA::selectToArray('contact', $fields, $condition, $params);
	}

	/**
	 * @param array $fields    Array of selected fields, empty for all
	 * @param array $condition Array of fields for condition
	 * @param array $params    Array of several parameters
	 * @return array
	 * @throws \Exception
	 */
	public static function selectFirst(array $fields = [], array $condition = [], array $params = [])
	{
		$contact = DBA::selectFirst('contact', $fields, $condition, $params);

		return $contact;
	}

	/**
	 * Insert a row into the contact table
	 * Important: You can't use DBA::lastInsertId() after this call since it will be set to 0.
	 *
	 * @param array $fields         field array
	 * @param int   $duplicate_mode Do an update on a duplicate entry
	 *
	 * @return int  id of the created contact
	 * @throws \Exception
	 */
	public static function insert(array $fields, int $duplicate_mode = Database::INSERT_DEFAULT)
	{
		if (!empty($fields['baseurl']) && empty($fields['gsid'])) {
			$fields['gsid'] = GServer::getID($fields['baseurl'], true);
		}

		$fields['uri-id'] = ItemURI::getIdByURI($fields['url']);

		if (empty($fields['created'])) {
			$fields['created'] = DateTimeFormat::utcNow();
		}

		DBA::insert('contact', $fields, $duplicate_mode);
		$contact = DBA::selectFirst('contact', [], ['id' => DBA::lastInsertId()]);
		if (!DBA::isResult($contact)) {
			// Shouldn't happen
			Logger::warning('Created contact could not be found', ['fields' => $fields]);
			return 0;
		}

		Contact\User::insertForContactArray($contact);

		// Search for duplicated contacts and get rid of them
		if (!$contact['self']) {
			self::removeDuplicates($contact['nurl'], $contact['uid']);
		}

		return $contact['id'];
	}

	/**
	 * Updates rows in the contact table
	 *
	 * @param array         $fields     contains the fields that are updated
	 * @param array         $condition  condition array with the key values
	 * @param array|boolean $old_fields array with the old field values that are about to be replaced (true = update on duplicate, false = don't update identical fields)
	 *
	 * @return boolean was the update successfull?
	 * @throws \Exception
	 */
	public static function update(array $fields, array $condition, $old_fields = [])
	{
		$ret = DBA::update('contact', $fields, $condition, $old_fields);

		// Apply changes to the "user-contact" table on dedicated fields
		Contact\User::updateByContactUpdate($fields, $condition);

		return $ret;
	}

	/**
	 * @param integer $id     Contact ID
	 * @param array   $fields Array of selected fields, empty for all
	 * @return array|boolean Contact record if it exists, false otherwise
	 * @throws \Exception
	 */
	public static function getById($id, $fields = [])
	{
		return DBA::selectFirst('contact', $fields, ['id' => $id]);
	}

	/**
	 * Fetch the first contact with the provided uri-id.
	 *
	 * @param integer $uri_id uri-id of the contact
	 * @param array   $fields Array of selected fields, empty for all
	 * @return array|boolean Contact record if it exists, false otherwise
	 * @throws \Exception
	 */
	public static function getByUriId($uri_id, $fields = [])
	{
		return DBA::selectFirst('contact', $fields, ['uri-id' => $uri_id], ['order' => ['uid']]);
	}

	/**
	 * Fetches a contact by a given url
	 *
	 * @param string  $url    profile url
	 * @param boolean $update true = always update, false = never update, null = update when not found or outdated
	 * @param array   $fields Field list
	 * @param integer $uid    User ID of the contact
	 * @return array contact array
	 */
	public static function getByURL(string $url, $update = null, array $fields = [], int $uid = 0)
	{
		if ($update || is_null($update)) {
			$cid = self::getIdForURL($url, $uid, $update);
			if (empty($cid)) {
				return [];
			}

			$contact = self::getById($cid, $fields);
			if (empty($contact)) {
				return [];
			}
			return $contact;
		}

		// Add internal fields
		$removal = [];
		if (!empty($fields)) {
			foreach (['id', 'avatar', 'created', 'updated', 'last-update', 'success_update', 'failure_update', 'network'] as $internal) {
				if (!in_array($internal, $fields)) {
					$fields[] = $internal;
					$removal[] = $internal;
				}
			}
		}

		// We first try the nurl (http://server.tld/nick), most common case
		$options = ['order' => ['id']];
		$contact = DBA::selectFirst('contact', $fields, ['nurl' => Strings::normaliseLink($url), 'uid' => $uid, 'deleted' => false], $options);

		// Then the addr (nick@server.tld)
		if (!DBA::isResult($contact)) {
			$contact = DBA::selectFirst('contact', $fields, ['addr' => str_replace('acct:', '', $url), 'uid' => $uid, 'deleted' => false], $options);
		}

		// Then the alias (which could be anything)
		if (!DBA::isResult($contact)) {
			// The link could be provided as http although we stored it as https
			$ssl_url = str_replace('http://', 'https://', $url);
			$condition = ['`alias` IN (?, ?, ?) AND `uid` = ? AND NOT `deleted`', $url, Strings::normaliseLink($url), $ssl_url, $uid];
			$contact = DBA::selectFirst('contact', $fields, $condition, $options);
		}

		if (!DBA::isResult($contact)) {
			return [];
		}

		// Update the contact in the background if needed
		$updated = max($contact['success_update'], $contact['created'], $contact['updated'], $contact['last-update'], $contact['failure_update']);
		if (($updated < DateTimeFormat::utc('now -7 days')) && in_array($contact['network'], Protocol::FEDERATED) && !self::isLocalById($contact['id'])) {
			Worker::add(PRIORITY_LOW, "UpdateContact", $contact['id']);
		}

		// Remove the internal fields
		foreach ($removal as $internal) {
			unset($contact[$internal]);
		}

		return $contact;
	}

	/**
	 * Fetches a contact for a given user by a given url.
	 * In difference to "getByURL" the function will fetch a public contact when no user contact had been found.
	 *
	 * @param string  $url    profile url
	 * @param integer $uid    User ID of the contact
	 * @param boolean $update true = always update, false = never update, null = update when not found or outdated
	 * @param array   $fields Field list
	 * @return array contact array
	 */
	public static function getByURLForUser(string $url, int $uid = 0, $update = false, array $fields = [])
	{
		if ($uid != 0) {
			$contact = self::getByURL($url, $update, $fields, $uid);
			if (!empty($contact)) {
				if (!empty($contact['id'])) {
					$contact['cid'] = $contact['id'];
					$contact['zid'] = 0;
				}
				return $contact;
			}
		}

		$contact = self::getByURL($url, $update, $fields);
		if (!empty($contact['id'])) {
			$contact['cid'] = 0;
			$contact['zid'] = $contact['id'];
		}
		return $contact;
	}

	/**
	 * Tests if the given contact is a follower
	 *
	 * @param int $cid Either public contact id or user's contact id
	 * @param int $uid User ID
	 *
	 * @return boolean is the contact id a follower?
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function isFollower($cid, $uid)
	{
		if (Contact\User::isBlocked($cid, $uid)) {
			return false;
		}

		$cdata = self::getPublicAndUserContactID($cid, $uid);
		if (empty($cdata['user'])) {
			return false;
		}

		$condition = ['id' => $cdata['user'], 'rel' => [self::FOLLOWER, self::FRIEND]];
		return DBA::exists('contact', $condition);
	}

	/**
	 * Tests if the given contact url is a follower
	 *
	 * @param string $url Contact URL
	 * @param int    $uid User ID
	 *
	 * @return boolean is the contact id a follower?
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function isFollowerByURL($url, $uid)
	{
		$cid = self::getIdForURL($url, $uid);

		if (empty($cid)) {
			return false;
		}

		return self::isFollower($cid, $uid);
	}

	/**
	 * Tests if the given user follow the given contact
	 *
	 * @param int $cid Either public contact id or user's contact id
	 * @param int $uid User ID
	 *
	 * @return boolean is the contact url being followed?
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function isSharing($cid, $uid)
	{
		if (Contact\User::isBlocked($cid, $uid)) {
			return false;
		}

		$cdata = self::getPublicAndUserContactID($cid, $uid);
		if (empty($cdata['user'])) {
			return false;
		}

		$condition = ['id' => $cdata['user'], 'rel' => [self::SHARING, self::FRIEND]];
		return DBA::exists('contact', $condition);
	}

	/**
	 * Tests if the given user follow the given contact url
	 *
	 * @param string $url Contact URL
	 * @param int    $uid User ID
	 *
	 * @return boolean is the contact url being followed?
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function isSharingByURL($url, $uid)
	{
		$cid = self::getIdForURL($url, $uid);

		if (empty($cid)) {
			return false;
		}

		return self::isSharing($cid, $uid);
	}

	/**
	 * Get the basepath for a given contact link
	 *
	 * @param string $url The contact link
	 * @param boolean $dont_update Don't update the contact
	 *
	 * @return string basepath
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function getBasepath($url, $dont_update = false)
	{
		$contact = DBA::selectFirst('contact', ['id', 'baseurl'], ['uid' => 0, 'nurl' => Strings::normaliseLink($url)]);
		if (!DBA::isResult($contact)) {
			return '';
		}

		if (!empty($contact['baseurl'])) {
			return $contact['baseurl'];
		} elseif ($dont_update) {
			return '';
		}

		// Update the existing contact
		self::updateFromProbe($contact['id']);

		// And fetch the result
		$contact = DBA::selectFirst('contact', ['baseurl'], ['id' => $contact['id']]);
		if (empty($contact['baseurl'])) {
			Logger::info('No baseurl for contact', ['url' => $url]);
			return '';
		}

		Logger::info('Found baseurl for contact', ['url' => $url, 'baseurl' => $contact['baseurl']]);
		return $contact['baseurl'];
	}

	/**
	 * Check if the given contact url is on the same server
	 *
	 * @param string $url The contact link
	 *
	 * @return boolean Is it the same server?
	 */
	public static function isLocal($url)
	{
		if (!parse_url($url, PHP_URL_SCHEME)) {
			$addr_parts = explode('@', $url);
			return (count($addr_parts) == 2) && ($addr_parts[1] == DI::baseUrl()->getHostname());
		}

		return Strings::compareLink(self::getBasepath($url, true), DI::baseUrl());
	}

	/**
	 * Check if the given contact ID is on the same server
	 *
	 * @param string $url The contact link
	 *
	 * @return boolean Is it the same server?
	 */
	public static function isLocalById(int $cid)
	{
		$contact = DBA::selectFirst('contact', ['url', 'baseurl'], ['id' => $cid]);
		if (!DBA::isResult($contact)) {
			return false;
		}

		if (empty($contact['baseurl'])) {
			$baseurl = self::getBasepath($contact['url'], true);
		} else {
			$baseurl = $contact['baseurl'];
		}

		return Strings::compareLink($baseurl, DI::baseUrl());
	}

	/**
	 * Returns the public contact id of the given user id
	 *
	 * @param  integer $uid User ID
	 *
	 * @return integer|boolean Public contact id for given user id
	 * @throws \Exception
	 */
	public static function getPublicIdByUserId($uid)
	{
		$self = DBA::selectFirst('contact', ['url'], ['self' => true, 'uid' => $uid]);
		if (!DBA::isResult($self)) {
			return false;
		}
		return self::getIdForURL($self['url']);
	}

	/**
	 * Returns the contact id for the user and the public contact id for a given contact id
	 *
	 * @param int $cid Either public contact id or user's contact id
	 * @param int $uid User ID
	 *
	 * @return array with public and user's contact id
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function getPublicAndUserContactID($cid, $uid)
	{
		// We have to use the legacy function as long as the post update hasn't finished
		if (DI::config()->get('system', 'post_update_version') < 1427) {
			return self::legacyGetPublicAndUserContactID($cid, $uid);
		}

		if (empty($uid) || empty($cid)) {
			return [];
		}

		$contact = DBA::selectFirst('account-user-view', ['id', 'uid', 'pid'], ['id' => $cid]);
		if (!DBA::isResult($contact) || !in_array($contact['uid'], [0, $uid])) {
			return [];
		}

		$pcid = $contact['pid'];
		if ($contact['uid'] == $uid) {
			$ucid = $contact['id'];
		} else {
			$contact = DBA::selectFirst('account-user-view', ['id', 'uid'], ['pid' => $cid, 'uid' => $uid]);
			if (DBA::isResult($contact)) {
				$ucid = $contact['id'];
			} else {
				$ucid = 0;
			}
		}

		return ['public' => $pcid, 'user' => $ucid];
	}

	/**
	 * Helper function for "getPublicAndUserContactID"
	 *
	 * @param int $cid Either public contact id or user's contact id
	 * @param int $uid User ID
	 *
	 * @return array with public and user's contact id
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function legacyGetPublicAndUserContactID($cid, $uid)
	{
		if (empty($uid) || empty($cid)) {
			return [];
		}

		$contact = DBA::selectFirst('contact', ['id', 'uid', 'url'], ['id' => $cid]);
		if (!DBA::isResult($contact)) {
			return [];
		}

		// We quit when the user id don't match the user id of the provided contact
		if (($contact['uid'] != $uid) && ($contact['uid'] != 0)) {
			return [];
		}

		if ($contact['uid'] != 0) {
			$pcid = self::getIdForURL($contact['url'], 0, false, ['url' => $contact['url']]);
			if (empty($pcid)) {
				return [];
			}
			$ucid = $contact['id'];
		} else {
			$pcid = $contact['id'];
			$ucid = self::getIdForURL($contact['url'], $uid);
		}

		return ['public' => $pcid, 'user' => $ucid];
	}

	/**
	 * Returns contact details for a given contact id in combination with a user id
	 *
	 * @param int $cid A contact ID
	 * @param int $uid The User ID
	 * @param array $fields The selected fields for the contact
	 *
	 * @return array The contact details
	 *
	 * @throws \Exception
	 */
	public static function getContactForUser($cid, $uid, array $fields = [])
	{
		$contact = DBA::selectFirst('contact', $fields, ['id' => $cid, 'uid' => $uid]);

		if (!DBA::isResult($contact)) {
			return [];
		} else {
			return $contact;
		}
	}

	/**
	 * Creates the self-contact for the provided user id
	 *
	 * @param int $uid
	 * @return bool Operation success
	 * @throws HTTPException\InternalServerErrorException
	 */
	public static function createSelfFromUserId($uid)
	{
		$user = DBA::selectFirst('user', ['uid', 'username', 'nickname', 'pubkey', 'prvkey'],
			['uid' => $uid, 'account_expired' => false]);
		if (!DBA::isResult($user)) {
			return false;
		}

		$contact = [
			'uid'         => $user['uid'],
			'created'     => DateTimeFormat::utcNow(),
			'self'        => 1,
			'name'        => $user['username'],
			'nick'        => $user['nickname'],
			'pubkey'      => $user['pubkey'],
			'prvkey'      => $user['prvkey'],
			'photo'       => User::getAvatarUrl($user),
			'thumb'       => User::getAvatarUrl($user, Proxy::SIZE_THUMB),
			'micro'       => User::getAvatarUrl($user, Proxy::SIZE_MICRO),
			'blocked'     => 0,
			'pending'     => 0,
			'url'         => DI::baseUrl() . '/profile/' . $user['nickname'],
			'nurl'        => Strings::normaliseLink(DI::baseUrl() . '/profile/' . $user['nickname']),
			'addr'        => $user['nickname'] . '@' . substr(DI::baseUrl(), strpos(DI::baseUrl(), '://') + 3),
			'request'     => DI::baseUrl() . '/dfrn_request/' . $user['nickname'],
			'notify'      => DI::baseUrl() . '/dfrn_notify/'  . $user['nickname'],
			'poll'        => DI::baseUrl() . '/dfrn_poll/'    . $user['nickname'],
			'confirm'     => DI::baseUrl() . '/dfrn_confirm/' . $user['nickname'],
			'poco'        => DI::baseUrl() . '/poco/'         . $user['nickname'],
			'name-date'   => DateTimeFormat::utcNow(),
			'uri-date'    => DateTimeFormat::utcNow(),
			'avatar-date' => DateTimeFormat::utcNow(),
			'closeness'   => 0
		];

		$return = true;

		// Only create the entry if it doesn't exist yet
		if (!DBA::exists('contact', ['uid' => $uid, 'self' => true])) {
			$return = (bool)self::insert($contact);
		}

		// Create the public contact
		if (!DBA::exists('contact', ['nurl' => $contact['nurl'], 'uid' => 0])) {
			$contact['self']   = false;
			$contact['uid']    = 0;
			$contact['prvkey'] = null;

			self::insert($contact, Database::INSERT_IGNORE);
		}

		return $return;
	}

	/**
	 * Updates the self-contact for the provided user id
	 *
	 * @param int     $uid
	 * @param boolean $update_avatar Force the avatar update
	 * @return bool   "true" if updated
	 * @throws HTTPException\InternalServerErrorException
	 */
	public static function updateSelfFromUserID($uid, $update_avatar = false)
	{
		$fields = ['id', 'name', 'nick', 'location', 'about', 'keywords', 'avatar', 'prvkey', 'pubkey', 'manually-approve',
			'xmpp', 'matrix', 'contact-type', 'forum', 'prv', 'avatar-date', 'url', 'nurl', 'unsearchable',
			'photo', 'thumb', 'micro', 'header', 'addr', 'request', 'notify', 'poll', 'confirm', 'poco', 'network'];
		$self = DBA::selectFirst('contact', $fields, ['uid' => $uid, 'self' => true]);
		if (!DBA::isResult($self)) {
			return false;
		}

		$fields = ['uid', 'nickname', 'page-flags', 'account-type', 'prvkey', 'pubkey'];
		$user = DBA::selectFirst('user', $fields, ['uid' => $uid, 'account_expired' => false]);
		if (!DBA::isResult($user)) {
			return false;
		}

		$fields = ['name', 'photo', 'thumb', 'about', 'address', 'locality', 'region',
			'country-name', 'pub_keywords', 'xmpp', 'matrix', 'net-publish'];
		$profile = DBA::selectFirst('profile', $fields, ['uid' => $uid]);
		if (!DBA::isResult($profile)) {
			return false;
		}

		$file_suffix = 'jpg';

		$fields = ['name' => $profile['name'], 'nick' => $user['nickname'],
			'avatar-date' => $self['avatar-date'], 'location' => Profile::formatLocation($profile),
			'about' => $profile['about'], 'keywords' => $profile['pub_keywords'],
			'contact-type' => $user['account-type'], 'prvkey' => $user['prvkey'],
			'pubkey' => $user['pubkey'], 'xmpp' => $profile['xmpp'], 'matrix' => $profile['matrix'], 'network' => Protocol::DFRN];

		// it seems as if ported accounts can have wrong values, so we make sure that now everything is fine.
		$fields['url'] = DI::baseUrl() . '/profile/' . $user['nickname'];
		$fields['nurl'] = Strings::normaliseLink($fields['url']);
		$fields['addr'] = $user['nickname'] . '@' . substr(DI::baseUrl(), strpos(DI::baseUrl(), '://') + 3);
		$fields['request'] = DI::baseUrl() . '/dfrn_request/' . $user['nickname'];
		$fields['notify'] = DI::baseUrl() . '/dfrn_notify/' . $user['nickname'];
		$fields['poll'] = DI::baseUrl() . '/dfrn_poll/'. $user['nickname'];
		$fields['confirm'] = DI::baseUrl() . '/dfrn_confirm/' . $user['nickname'];
		$fields['poco'] = DI::baseUrl() . '/poco/' . $user['nickname'];

		$avatar = Photo::selectFirst(['resource-id', 'type'], ['uid' => $uid, 'profile' => true]);
		if (DBA::isResult($avatar)) {
			if ($update_avatar) {
				$fields['avatar-date'] = DateTimeFormat::utcNow();
			}

			// Creating the path to the avatar, beginning with the file suffix
			$types = Images::supportedTypes();
			if (isset($types[$avatar['type']])) {
				$file_suffix = $types[$avatar['type']];
			}

			// We are adding a timestamp value so that other systems won't use cached content
			$timestamp = strtotime($fields['avatar-date']);

			$prefix = DI::baseUrl() . '/photo/' .$avatar['resource-id'] . '-';
			$suffix = '.' . $file_suffix . '?ts=' . $timestamp;

			$fields['photo'] = $prefix . '4' . $suffix;
			$fields['thumb'] = $prefix . '5' . $suffix;
			$fields['micro'] = $prefix . '6' . $suffix;
		} else {
			// We hadn't found a photo entry, so we use the default avatar
			$fields['photo'] = self::getDefaultAvatar($fields, Proxy::SIZE_SMALL);
			$fields['thumb'] = self::getDefaultAvatar($fields, Proxy::SIZE_THUMB);
			$fields['micro'] = self::getDefaultAvatar($fields, Proxy::SIZE_MICRO);
		}

		$fields['avatar'] = User::getAvatarUrl($user);
		$fields['header'] = User::getBannerUrl($user);
		$fields['forum'] = $user['page-flags'] == User::PAGE_FLAGS_COMMUNITY;
		$fields['prv'] = $user['page-flags'] == User::PAGE_FLAGS_PRVGROUP;
		$fields['unsearchable'] = !$profile['net-publish'];
		$fields['manually-approve'] = in_array($user['page-flags'], [User::PAGE_FLAGS_NORMAL, User::PAGE_FLAGS_PRVGROUP]);

		$update = false;

		foreach ($fields as $field => $content) {
			if ($self[$field] != $content) {
				$update = true;
			}
		}

		if ($update) {
			if ($fields['name'] != $self['name']) {
				$fields['name-date'] = DateTimeFormat::utcNow();
			}
			$fields['updated'] = DateTimeFormat::utcNow();
			self::update($fields, ['id' => $self['id']]);

			// Update the public contact as well
			$fields['prvkey'] = null;
			$fields['self']   = false;
			self::update($fields, ['uid' => 0, 'nurl' => $self['nurl']]);

			// Update the profile
			$fields = [
				'photo' => User::getAvatarUrl($user),
				'thumb' => User::getAvatarUrl($user, Proxy::SIZE_THUMB)
			];

			DBA::update('profile', $fields, ['uid' => $uid]);
		}

		return $update;
	}

	/**
	 * Marks a contact for removal
	 *
	 * @param int $id contact id
	 * @throws HTTPException\InternalServerErrorException
	 */
	public static function remove($id)
	{
		// We want just to make sure that we don't delete our "self" contact
		$contact = DBA::selectFirst('contact', ['uid'], ['id' => $id, 'self' => false]);
		if (!DBA::isResult($contact)) {
			return;
		}

		// Archive the contact
		self::update(['archive' => true, 'network' => Protocol::PHANTOM, 'deleted' => true], ['id' => $id]);

		// Delete it in the background
		Worker::add(PRIORITY_MEDIUM, 'Contact\Remove', $id);
	}

	/**
	 * Sends an unfriend message. Removes the contact for two-way unfriending or sharing only protocols (feed an mail)
	 *
	 * @param array   $user    User unfriending
	 * @param array   $contact Contact (uid != 0) unfriended
	 * @param boolean $two_way Revoke eventual inbound follow as well
	 * @return bool|null true if successful, false if not, null if no remote action was performed
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function terminateFriendship(array $user, array $contact): ?bool
	{
		$result = Protocol::terminateFriendship($user, $contact);

		if ($contact['rel'] == Contact::SHARING || in_array($contact['network'], [Protocol::FEED, Protocol::MAIL])) {
			self::remove($contact['id']);
		} else {
			self::update(['rel' => Contact::FOLLOWER], ['id' => $contact['id']]);
		}

		return $result;
	}

	/**
	 * Revoke follow privileges of the remote user contact
	 *
	 * @param array   $contact  Contact unfriended
	 * @return bool|null Whether the remote operation is successful or null if no remote operation was performed
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function revokeFollow(array $contact): ?bool
	{
		if (empty($contact['network'])) {
			throw new \InvalidArgumentException('Empty network in contact array');
		}

		if (empty($contact['uid'])) {
			throw new \InvalidArgumentException('Unexpected public contact record');
		}

		$result = Protocol::revokeFollow($contact);

		// A null value here means the remote network doesn't support explicit follow revocation, we can still
		// break the locally recorded relationship
		if ($result !== false) {
			if ($contact['rel'] == self::FRIEND) {
				self::update(['rel' => self::SHARING], ['id' => $contact['id']]);
			} else {
				self::remove($contact['id']);
			}
		}

		return $result;
	}


	/**
	 * Marks a contact for archival after a communication issue delay
	 *
	 * Contact has refused to recognise us as a friend. We will start a countdown.
	 * If they still don't recognise us in 32 days, the relationship is over,
	 * and we won't waste any more time trying to communicate with them.
	 * This provides for the possibility that their database is temporarily messed
	 * up or some other transient event and that there's a possibility we could recover from it.
	 *
	 * @param array $contact contact to mark for archival
	 * @return null
	 * @throws HTTPException\InternalServerErrorException
	 */
	public static function markForArchival(array $contact)
	{
		if (!isset($contact['url']) && !empty($contact['id'])) {
			$fields = ['id', 'url', 'archive', 'self', 'term-date'];
			$contact = DBA::selectFirst('contact', $fields, ['id' => $contact['id']]);
			if (!DBA::isResult($contact)) {
				return;
			}
		} elseif (!isset($contact['url'])) {
			Logger::info('Empty contact', ['contact' => $contact, 'callstack' => System::callstack(20)]);
		}

		Logger::info('Contact is marked for archival', ['id' => $contact['id'], 'term-date' => $contact['term-date']]);

		// Contact already archived or "self" contact? => nothing to do
		if ($contact['archive'] || $contact['self']) {
			return;
		}

		if ($contact['term-date'] <= DBA::NULL_DATETIME) {
			self::update(['term-date' => DateTimeFormat::utcNow()], ['id' => $contact['id']]);
			self::update(['term-date' => DateTimeFormat::utcNow()], ['`nurl` = ? AND `term-date` <= ? AND NOT `self`', Strings::normaliseLink($contact['url']), DBA::NULL_DATETIME]);
		} else {
			/* @todo
			 * We really should send a notification to the owner after 2-3 weeks
			 * so they won't be surprised when the contact vanishes and can take
			 * remedial action if this was a serious mistake or glitch
			 */

			/// @todo Check for contact vitality via probing
			$archival_days = DI::config()->get('system', 'archival_days', 32);

			$expiry = $contact['term-date'] . ' + ' . $archival_days . ' days ';
			if (DateTimeFormat::utcNow() > DateTimeFormat::utc($expiry)) {
				/* Relationship is really truly dead. archive them rather than
				 * delete, though if the owner tries to unarchive them we'll start
				 * the whole process over again.
				 */
				self::update(['archive' => true], ['id' => $contact['id']]);
				self::update(['archive' => true], ['nurl' => Strings::normaliseLink($contact['url']), 'self' => false]);
			}
		}
	}

	/**
	 * Cancels the archival countdown
	 *
	 * @see   Contact::markForArchival()
	 *
	 * @param array $contact contact to be unmarked for archival
	 * @return null
	 * @throws \Exception
	 */
	public static function unmarkForArchival(array $contact)
	{
		// Always unarchive the relay contact entry
		if (!empty($contact['batch']) && !empty($contact['term-date']) && ($contact['term-date'] > DBA::NULL_DATETIME)) {
			$fields = ['failed' => false, 'term-date' => DBA::NULL_DATETIME, 'archive' => false];
			$condition = ['uid' => 0, 'network' => Protocol::FEDERATED, 'batch' => $contact['batch'], 'contact-type' => self::TYPE_RELAY];
			if (!DBA::exists('contact', array_merge($condition, $fields))) {
				self::update($fields, $condition);
			}
		}

		$condition = ['`id` = ? AND (`term-date` > ? OR `archive`)', $contact['id'], DBA::NULL_DATETIME];
		$exists = DBA::exists('contact', $condition);

		// We don't need to update, we never marked this contact for archival
		if (!$exists) {
			return;
		}

		Logger::info('Contact is marked as vital again', ['id' => $contact['id'], 'term-date' => $contact['term-date']]);

		if (!isset($contact['url']) && !empty($contact['id'])) {
			$fields = ['id', 'url', 'batch'];
			$contact = DBA::selectFirst('contact', $fields, ['id' => $contact['id']]);
			if (!DBA::isResult($contact)) {
				return;
			}
		}

		// It's a miracle. Our dead contact has inexplicably come back to life.
		$fields = ['failed' => false, 'term-date' => DBA::NULL_DATETIME, 'archive' => false];
		self::update($fields, ['id' => $contact['id']]);
		self::update($fields, ['nurl' => Strings::normaliseLink($contact['url']), 'self' => false]);
	}

	/**
	 * Returns the data array for the photo menu of a given contact
	 *
	 * @param array $contact contact
	 * @param int   $uid     optional, default 0
	 * @return array
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function photoMenu(array $contact, $uid = 0)
	{
		$pm_url = '';
		$status_link = '';
		$photos_link = '';
		$poke_link = '';

		if ($uid == 0) {
			$uid = local_user();
		}

		if (empty($contact['uid']) || ($contact['uid'] != $uid)) {
			if ($uid == 0) {
				$profile_link = self::magicLinkByContact($contact);
				$menu = ['profile' => [DI::l10n()->t('View Profile'), $profile_link, true]];

				return $menu;
			}

			// Look for our own contact if the uid doesn't match and isn't public
			$contact_own = DBA::selectFirst('contact', [], ['nurl' => $contact['nurl'], 'network' => $contact['network'], 'uid' => $uid]);
			if (DBA::isResult($contact_own)) {
				return self::photoMenu($contact_own, $uid);
			}
		}

		$sparkle = false;
		if (($contact['network'] === Protocol::DFRN) && !$contact['self'] && empty($contact['pending'])) {
			$sparkle = true;
			$profile_link = DI::baseUrl() . '/redir/' . $contact['id'];
		} else {
			$profile_link = $contact['url'];
		}

		if ($profile_link === 'mailbox') {
			$profile_link = '';
		}

		if ($sparkle) {
			$status_link = $profile_link . '/status';
			$photos_link = str_replace('/profile/', '/photos/', $profile_link);
			$profile_link = $profile_link . '/profile';
		}

		if (self::canReceivePrivateMessages($contact) && empty($contact['pending'])) {
			$pm_url = DI::baseUrl() . '/message/new/' . $contact['id'];
		}

		if (($contact['network'] == Protocol::DFRN) && !$contact['self'] && empty($contact['pending'])) {
			$poke_link = 'contact/' . $contact['id'] . '/poke';
		}

		$contact_url = DI::baseUrl() . '/contact/' . $contact['id'];

		$posts_link = DI::baseUrl() . '/contact/' . $contact['id'] . '/conversations';

		$follow_link = '';
		$unfollow_link = '';
		if (!$contact['self'] && Protocol::supportsFollow($contact['network'])) {
			if ($contact['uid'] && in_array($contact['rel'], [self::SHARING, self::FRIEND])) {
				$unfollow_link = 'unfollow?url=' . urlencode($contact['url']) . '&auto=1';
			} elseif(!$contact['pending']) {
				$follow_link = 'follow?url=' . urlencode($contact['url']) . '&auto=1';
			}
		}

		/**
		 * Menu array:
		 * "name" => [ "Label", "link", (bool)Should the link opened in a new tab? ]
		 */
		if (empty($contact['uid'])) {
			$menu = [
				'profile' => [DI::l10n()->t('View Profile')  , $profile_link , true],
				'network' => [DI::l10n()->t('Network Posts') , $posts_link   , false],
				'edit'    => [DI::l10n()->t('View Contact')  , $contact_url  , false],
				'follow'  => [DI::l10n()->t('Connect/Follow'), $follow_link  , true],
				'unfollow'=> [DI::l10n()->t('UnFollow')      , $unfollow_link, true],
			];
		} else {
			$menu = [
				'status'  => [DI::l10n()->t('View Status')   , $status_link      , true],
				'profile' => [DI::l10n()->t('View Profile')  , $profile_link     , true],
				'photos'  => [DI::l10n()->t('View Photos')   , $photos_link      , true],
				'network' => [DI::l10n()->t('Network Posts') , $posts_link       , false],
				'edit'    => [DI::l10n()->t('View Contact')  , $contact_url      , false],
				'pm'      => [DI::l10n()->t('Send PM')       , $pm_url           , false],
				'poke'    => [DI::l10n()->t('Poke')          , $poke_link        , false],
				'follow'  => [DI::l10n()->t('Connect/Follow'), $follow_link      , true],
				'unfollow'=> [DI::l10n()->t('UnFollow')      , $unfollow_link    , true],
			];

			if (!empty($contact['pending'])) {
				try {
					$intro = DI::intro()->selectForContact($contact['id']);
					$menu['follow'] = [DI::l10n()->t('Approve'), 'notifications/intros/' . $intro->id, true];
				} catch (IntroductionNotFoundException $exception) {
					DI::logger()->error('Pending contact doesn\'t have an introduction.', ['exception' => $exception]);
				}
			}
		}

		$args = ['contact' => $contact, 'menu' => &$menu];

		Hook::callAll('contact_photo_menu', $args);

		$menucondensed = [];

		foreach ($menu as $menuname => $menuitem) {
			if ($menuitem[1] != '') {
				$menucondensed[$menuname] = $menuitem;
			}
		}

		return $menucondensed;
	}

	/**
	 * Fetch the contact id for a given URL and user
	 *
	 * First lookup in the contact table to find a record matching either `url`, `nurl`,
	 * `addr` or `alias`.
	 *
	 * If there's no record and we aren't looking for a public contact, we quit.
	 * If there's one, we check that it isn't time to update the picture else we
	 * directly return the found contact id.
	 *
	 * Second, we probe the provided $url whether it's http://server.tld/profile or
	 * nick@server.tld. We quit if we can't get any info back.
	 *
	 * Third, we create the contact record if it doesn't exist
	 *
	 * Fourth, we update the existing record with the new data (avatar, alias, nick)
	 * if there's any updates
	 *
	 * @param string  $url       Contact URL
	 * @param integer $uid       The user id for the contact (0 = public contact)
	 * @param boolean $update    true = always update, false = never update, null = update when not found
	 * @param array   $default   Default value for creating the contact when everything else fails
	 *
	 * @return integer Contact ID
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function getIdForURL($url, $uid = 0, $update = null, $default = [])
	{
		$contact_id = 0;

		if ($url == '') {
			Logger::notice('Empty url, quitting', ['url' => $url, 'user' => $uid, 'default' => $default]);
			return 0;
		}

		$contact = self::getByURL($url, false, ['id', 'network', 'uri-id'], $uid);

		if (!empty($contact)) {
			$contact_id = $contact["id"];

			if (empty($update) && (!empty($contact['uri-id']) || is_bool($update))) {
				Logger::debug('Contact found', ['url' => $url, 'uid' => $uid, 'update' => $update, 'cid' => $contact_id]);
				return $contact_id;
			}
		} elseif ($uid != 0) {
			Logger::debug('Contact does not exist for the user', ['url' => $url, 'uid' => $uid, 'update' => $update]);
			return 0;
		} elseif (empty($default) && !is_null($update) && !$update) {
			Logger::info('Contact not found, update not desired', ['url' => $url, 'uid' => $uid, 'update' => $update]);
			return 0;
		}

		$data = [];

		if (empty($default['network']) || $update) {
			$data = Probe::uri($url, "", $uid);

			// Take the default values when probing failed
			if (!empty($default) && !in_array($data["network"], array_merge(Protocol::NATIVE_SUPPORT, [Protocol::PUMPIO]))) {
				$data = array_merge($data, $default);
			}
		} elseif (!empty($default['network'])) {
			$data = $default;
		}

		if (($uid == 0) && (empty($data['network']) || ($data['network'] == Protocol::PHANTOM))) {
			// Fetch data for the public contact via the first found personal contact
			/// @todo Check if this case can happen at all (possibly with mail accounts?)
			$fields = ['name', 'nick', 'url', 'addr', 'alias', 'avatar', 'header', 'contact-type',
				'keywords', 'location', 'about', 'unsearchable', 'batch', 'notify', 'poll',
				'request', 'confirm', 'poco', 'subscribe', 'network', 'baseurl', 'gsid'];

			$personal_contact = DBA::selectFirst('contact', $fields, ["`addr` = ? AND `uid` != 0", $url]);
			if (!DBA::isResult($personal_contact)) {
				$personal_contact = DBA::selectFirst('contact', $fields, ["`nurl` = ? AND `uid` != 0", Strings::normaliseLink($url)]);
			}

			if (DBA::isResult($personal_contact)) {
				Logger::info('Take contact data from personal contact', ['url' => $url, 'update' => $update, 'contact' => $personal_contact, 'callstack' => System::callstack(20)]);
				$data = $personal_contact;
				$data['photo'] = $personal_contact['avatar'];
				$data['account-type'] = $personal_contact['contact-type'];
				$data['hide'] = $personal_contact['unsearchable'];
				unset($data['avatar']);
				unset($data['contact-type']);
				unset($data['unsearchable']);
			}
		}

		if (empty($data['network']) || ($data['network'] == Protocol::PHANTOM)) {
			Logger::notice('No valid network found', ['url' => $url, 'uid' => $uid, 'default' => $default, 'update' => $update, 'callstack' => System::callstack(20)]);
			return 0;
		}

		if (!$contact_id) {
			$urls = [Strings::normaliseLink($url), Strings::normaliseLink($data['url'])];
			if (!empty($data['alias'])) {
				$urls[] = Strings::normaliseLink($data['alias']);
			}
			$contact = self::selectFirst(['id'], ['nurl' => $urls, 'uid' => $uid]);
			if (!empty($contact['id'])) {
				$contact_id = $contact['id'];
				Logger::info('Fetched id by url', ['cid' => $contact_id, 'uid' => $uid, 'url' => $url, 'data' => $data]);
			}
		}

		if (!$contact_id) {
			// We only insert the basic data. The rest will be done in "updateFromProbeArray"
			$fields = [
				'uid'       => $uid,
				'url'       => $data['url'],
				'nurl'      => Strings::normaliseLink($data['url']),
				'network'   => $data['network'],
				'created'   => DateTimeFormat::utcNow(),
				'rel'       => self::SHARING,
				'writable'  => 1,
				'blocked'   => 0,
				'readonly'  => 0,
				'pending'   => 0];

			$condition = ['nurl' => Strings::normaliseLink($data["url"]), 'uid' => $uid, 'deleted' => false];

			// Before inserting we do check if the entry does exist now.
			if (DI::lock()->acquire(self::LOCK_INSERT, 0)) {
				$contact = DBA::selectFirst('contact', ['id'], $condition, ['order' => ['id']]);
				if (DBA::isResult($contact)) {
					$contact_id = $contact['id'];
					Logger::notice('Contact had been created (shortly) before', ['id' => $contact_id, 'url' => $url, 'uid' => $uid]);
				} else {
					$contact_id = self::insert($fields);
					if ($contact_id) {
						Logger::info('Contact inserted', ['id' => $contact_id, 'url' => $url, 'uid' => $uid]);
					}
				}
				DI::lock()->release(self::LOCK_INSERT);
			} else {
				Logger::warning('Contact lock had not been acquired');
			}

			if (!$contact_id) {
				Logger::info('Contact was not inserted', ['url' => $url, 'uid' => $uid]);
				return 0;
			}
		} else {
			Logger::info('Contact will be updated', ['url' => $url, 'uid' => $uid, 'update' => $update, 'cid' => $contact_id]);
		}

		if ($data['network'] == Protocol::DIASPORA) {
			FContact::updateFromProbeArray($data);
		}

		self::updateFromProbeArray($contact_id, $data);

		// Don't return a number for a deleted account
		if (!empty($data['account-type']) && $data['account-type'] == User::ACCOUNT_TYPE_DELETED) {
			Logger::info('Contact is a tombstone', ['url' => $url, 'uid' => $uid]);
			return 0;
		}

		return $contact_id;
	}

	/**
	 * Checks if the contact is archived
	 *
	 * @param int $cid contact id
	 *
	 * @return boolean Is the contact archived?
	 * @throws HTTPException\InternalServerErrorException
	 */
	public static function isArchived(int $cid)
	{
		if ($cid == 0) {
			return false;
		}

		$contact = DBA::selectFirst('contact', ['archive', 'url', 'batch'], ['id' => $cid]);
		if (!DBA::isResult($contact)) {
			return false;
		}

		if ($contact['archive']) {
			return true;
		}

		// Check status of ActivityPub endpoints
		$apcontact = APContact::getByURL($contact['url'], false);
		if (!empty($apcontact)) {
			if (!empty($apcontact['inbox']) && DBA::exists('inbox-status', ['archive' => true, 'url' => $apcontact['inbox']])) {
				return true;
			}

			if (!empty($apcontact['sharedinbox']) && DBA::exists('inbox-status', ['archive' => true, 'url' => $apcontact['sharedinbox']])) {
				return true;
			}
		}

		// Check status of Diaspora endpoints
		if (!empty($contact['batch'])) {
			$condition = ['archive' => true, 'uid' => 0, 'network' => Protocol::FEDERATED, 'batch' => $contact['batch'], 'contact-type' => self::TYPE_RELAY];
			return DBA::exists('contact', $condition);
                }

		return false;
	}

	/**
	 * Checks if the contact is blocked
	 *
	 * @param int $cid contact id
	 *
	 * @return boolean Is the contact blocked?
	 * @throws HTTPException\InternalServerErrorException
	 */
	public static function isBlocked($cid)
	{
		if ($cid == 0) {
			return false;
		}

		$blocked = DBA::selectFirst('contact', ['blocked', 'url'], ['id' => $cid]);
		if (!DBA::isResult($blocked)) {
			return false;
		}

		if (Network::isUrlBlocked($blocked['url'])) {
			return true;
		}

		return (bool) $blocked['blocked'];
	}

	/**
	 * Checks if the contact is hidden
	 *
	 * @param int $cid contact id
	 *
	 * @return boolean Is the contact hidden?
	 * @throws \Exception
	 */
	public static function isHidden($cid)
	{
		if ($cid == 0) {
			return false;
		}

		$hidden = DBA::selectFirst('contact', ['hidden'], ['id' => $cid]);
		if (!DBA::isResult($hidden)) {
			return false;
		}
		return (bool) $hidden['hidden'];
	}

	/**
	 * Returns posts from a given contact url
	 *
	 * @param string $contact_url Contact URL
	 * @param bool   $thread_mode
	 * @param int    $update      Update mode
	 * @param int    $parent      Item parent ID for the update mode
	 * @param bool   $only_media  Only display media content
	 * @return string posts in HTML
	 * @throws \Exception
	 */
	public static function getPostsFromUrl($contact_url, $thread_mode = false, $update = 0, $parent = 0, bool $only_media = false)
	{
		return self::getPostsFromId(self::getIdForURL($contact_url), $thread_mode, $update, $parent, $only_media);
	}

	/**
	 * Returns posts from a given contact id
	 *
	 * @param int  $cid         Contact ID
	 * @param bool $thread_mode
	 * @param int  $update      Update mode
	 * @param int  $parent      Item parent ID for the update mode
	 * @param bool $only_media  Only display media content
	 * @return string posts in HTML
	 * @throws \Exception
	 */
	public static function getPostsFromId($cid, $thread_mode = false, $update = 0, $parent = 0, bool $only_media = false)
	{
		$contact = DBA::selectFirst('contact', ['contact-type', 'network'], ['id' => $cid]);
		if (!DBA::isResult($contact)) {
			return '';
		}

		if (empty($contact["network"]) || in_array($contact["network"], Protocol::FEDERATED)) {
			$sql = "(`uid` = 0 OR (`uid` = ? AND NOT `global`))";
		} else {
			$sql = "`uid` = ?";
		}

		$contact_field = ((($contact["contact-type"] == self::TYPE_COMMUNITY) || ($contact['network'] == Protocol::MAIL)) ? 'owner-id' : 'author-id');

		if ($thread_mode) {
			$condition = ["((`$contact_field` = ? AND `gravity` = ?) OR (`author-id` = ? AND `gravity` = ? AND `vid` = ?)) AND " . $sql,
				$cid, GRAVITY_PARENT, $cid, GRAVITY_ACTIVITY, Verb::getID(Activity::ANNOUNCE), local_user()];
		} else {
			$condition = ["`$contact_field` = ? AND `gravity` IN (?, ?) AND " . $sql,
				$cid, GRAVITY_PARENT, GRAVITY_COMMENT, local_user()];
		}

		if (!empty($parent)) {
			$condition = DBA::mergeConditions($condition, ['parent' => $parent]);
		} else {
			$last_received = isset($_GET['last_received']) ? DateTimeFormat::utc($_GET['last_received']) : '';
			if (!empty($last_received)) {
				$condition = DBA::mergeConditions($condition, ["`received` < ?", $last_received]);
			}
		}

		if ($only_media) {
			$condition = DBA::mergeConditions($condition, ["`uri-id` IN (SELECT `uri-id` FROM `post-media` WHERE `type` IN (?, ?, ?))",
				Post\Media::AUDIO, Post\Media::IMAGE, Post\Media::VIDEO]);
		}

		if (DI::mode()->isMobile()) {
			$itemsPerPage = DI::pConfig()->get(local_user(), 'system', 'itemspage_mobile_network',
				DI::config()->get('system', 'itemspage_network_mobile'));
		} else {
			$itemsPerPage = DI::pConfig()->get(local_user(), 'system', 'itemspage_network',
				DI::config()->get('system', 'itemspage_network'));
		}

		$pager = new Pager(DI::l10n(), DI::args()->getQueryString(), $itemsPerPage);

		$params = ['order' => ['received' => true], 'limit' => [$pager->getStart(), $pager->getItemsPerPage()]];

		if (DI::pConfig()->get(local_user(), 'system', 'infinite_scroll')) {
			$tpl = Renderer::getMarkupTemplate('infinite_scroll_head.tpl');
			$o = Renderer::replaceMacros($tpl, ['$reload_uri' => DI::args()->getQueryString()]);
		} else {
			$o = '';
		}

		if ($thread_mode) {
			$items = Post::toArray(Post::selectForUser(local_user(), ['uri-id', 'gravity', 'parent-uri-id', 'thr-parent-id', 'author-id'], $condition, $params));

			$o .= DI::conversation()->create($items, 'contacts', $update, false, 'commented', local_user());
		} else {
			$items = Post::toArray(Post::selectForUser(local_user(), Item::DISPLAY_FIELDLIST, $condition, $params));

			$o .= DI::conversation()->create($items, 'contact-posts', $update);
		}

		if (!$update) {
			if (DI::pConfig()->get(local_user(), 'system', 'infinite_scroll')) {
				$o .= HTML::scrollLoader();
			} else {
				$o .= $pager->renderMinimal(count($items));
			}
		}

		return $o;
	}

	/**
	 * Returns the account type name
	 *
	 * The function can be called with either the user or the contact array
	 *
	 * @param int $type type of contact or account
	 * @return string
	 */
	public static function getAccountType(int $type)
	{
		switch ($type) {
			case self::TYPE_ORGANISATION:
				$account_type = DI::l10n()->t("Organisation");
				break;

			case self::TYPE_NEWS:
				$account_type = DI::l10n()->t('News');
				break;

			case self::TYPE_COMMUNITY:
				$account_type = DI::l10n()->t("Forum");
				break;

			default:
				$account_type = "";
				break;
		}

		return $account_type;
	}

	/**
	 * Blocks a contact
	 *
	 * @param int $cid
	 * @return bool
	 * @throws \Exception
	 */
	public static function block($cid, $reason = null)
	{
		$return = self::update(['blocked' => true, 'block_reason' => $reason], ['id' => $cid]);

		return $return;
	}

	/**
	 * Unblocks a contact
	 *
	 * @param int $cid
	 * @return bool
	 * @throws \Exception
	 */
	public static function unblock($cid)
	{
		$return = self::update(['blocked' => false, 'block_reason' => null], ['id' => $cid]);

		return $return;
	}

	/**
	 * Ensure that cached avatar exist
	 *
	 * @param integer $cid
	 */
	public static function checkAvatarCache(int $cid)
	{
		$contact = DBA::selectFirst('contact', ['url', 'network', 'avatar', 'photo', 'thumb', 'micro'], ['id' => $cid, 'uid' => 0, 'self' => false]);
		if (!DBA::isResult($contact)) {
			return;
		}

		if (in_array($contact['network'], [Protocol::FEED, Protocol::MAIL]) || DI::config()->get('system', 'cache_contact_avatar')) {
			if (!empty($contact['avatar']) && (empty($contact['photo']) || empty($contact['thumb']) || empty($contact['micro']))) {
				Logger::info('Adding avatar cache', ['id' => $cid, 'contact' => $contact]);
				self::updateAvatar($cid, $contact['avatar'], true);
				return;
			}
		} elseif (!empty($contact['photo']) || !empty($contact['thumb']) || !empty($contact['micro'])) {
			Logger::info('Removing avatar cache', ['id' => $cid, 'contact' => $contact]);
			self::updateAvatar($cid, $contact['avatar'], true);
			return;
		}
	}

	/**
	 * Return the photo path for a given contact array in the given size
	 *
	 * @param array $contact    contact array
	 * @param string $field     Fieldname of the photo in the contact array
	 * @param string $size      Size of the avatar picture
	 * @param string $avatar    Avatar path that is displayed when no photo had been found
	 * @param bool  $no_update Don't perfom an update if no cached avatar was found
	 * @return string photo path
	 */
	private static function getAvatarPath(array $contact, string $size, $no_update = false)
	{
		$contact = self::checkAvatarCacheByArray($contact, $no_update);
		return self::getAvatarUrlForId($contact['id'], $size, $contact['updated'] ?? '');
	}

	/**
	 * Return the photo path for a given contact array
	 *
	 * @param array  $contact   Contact array
	 * @param bool   $no_update Don't perfom an update if no cached avatar was found
	 * @return string photo path
	 */
	public static function getPhoto(array $contact, bool $no_update = false)
	{
		return self::getAvatarPath($contact, Proxy::SIZE_SMALL, $no_update);
	}

	/**
	 * Return the photo path (thumb size) for a given contact array
	 *
	 * @param array  $contact   Contact array
	 * @param bool   $no_update Don't perfom an update if no cached avatar was found
	 * @return string photo path
	 */
	public static function getThumb(array $contact, bool $no_update = false)
	{
		return self::getAvatarPath($contact, Proxy::SIZE_THUMB, $no_update);
	}

	/**
	 * Return the photo path (micro size) for a given contact array
	 *
	 * @param array  $contact   Contact array
	 * @param bool   $no_update Don't perfom an update if no cached avatar was found
	 * @return string photo path
	 */
	public static function getMicro(array $contact, bool $no_update = false)
	{
		return self::getAvatarPath($contact, Proxy::SIZE_MICRO, $no_update);
	}

	/**
	 * Check the given contact array for avatar cache fields
	 *
	 * @param array $contact
	 * @param bool  $no_update Don't perfom an update if no cached avatar was found
	 * @return array contact array with avatar cache fields
	 */
	private static function checkAvatarCacheByArray(array $contact, bool $no_update = false)
	{
		$update = false;
		$contact_fields = [];
		$fields = ['photo', 'thumb', 'micro'];
		foreach ($fields as $field) {
			if (isset($contact[$field])) {
				$contact_fields[] = $field;
			}
			if (isset($contact[$field]) && empty($contact[$field])) {
				$update = true;
			}
		}

		if (!$update || $no_update) {
			return $contact;
		}

		if (!empty($contact['id']) && !empty($contact['avatar'])) {
			self::updateAvatar($contact['id'], $contact['avatar'], true);

			$new_contact = self::getById($contact['id'], $contact_fields);
			if (DBA::isResult($new_contact)) {
				// We only update the cache fields
				$contact = array_merge($contact, $new_contact);
			}
		}

		/// add the default avatars if the fields aren't filled
		if (isset($contact['photo']) && empty($contact['photo'])) {
			$contact['photo'] = self::getDefaultAvatar($contact, Proxy::SIZE_SMALL);
		}
		if (isset($contact['thumb']) && empty($contact['thumb'])) {
			$contact['thumb'] = self::getDefaultAvatar($contact, Proxy::SIZE_THUMB);
		}
		if (isset($contact['micro']) && empty($contact['micro'])) {
			$contact['micro'] = self::getDefaultAvatar($contact, Proxy::SIZE_MICRO);
		}

		return $contact;
	}

	/**
	 * Fetch the default header for the given contact
	 *
	 * @param array $contact  contact array
	 * @return string avatar URL
	 */
	public static function getDefaultHeader(array $contact): string
	{
		if (!empty($contact['header'])) {
			return $contact['header'];
		}

		if (!empty($contact['gsid'])) {
			// Use default banners for certain platforms
			$gserver = DBA::selectFirst('gserver', ['platform'], ['id' => $contact['gsid']]);
			$platform = strtolower($gserver['platform'] ?? '');
		} else {
			$platform = '';
		}

		switch ($platform) {
			case 'friendica':
			case 'friendika':
				/**
				 * Picture credits
				 * @author  Lostinlight <https://mastodon.xyz/@lightone>
				 * @license CC0 https://creativecommons.org/share-your-work/public-domain/cc0/
				 * @link    https://gitlab.com/lostinlight/per_aspera_ad_astra/-/blob/master/friendica-404/friendica-promo-bubbles.jpg
				 */
				$header = DI::baseUrl() . '/images/friendica-banner.jpg';
				break;
			case 'diaspora':
				/**
				 * Picture credits
				 * @author  John Liu <https://www.flickr.com/photos/8047705@N02/>
				 * @license CC BY 2.0 https://creativecommons.org/licenses/by/2.0/
				 * @link    https://www.flickr.com/photos/8047705@N02/5572197407
				 */
				$header = DI::baseUrl() . '/images/diaspora-banner.jpg';
				break;
			default:
				/**
				 * Use a random picture. 
				 * The service provides random pictures from Unsplash.
				 * @license https://unsplash.com/license
				 */
				$header = 'https://picsum.photos/seed/' . hash('ripemd128', $contact['url']) . '/960/300';
				break;
		}

		return $header;
	}

	/**
	 * Fetch the default avatar for the given contact and size
	 *
	 * @param array $contact  contact array
	 * @param string $size    Size of the avatar picture
	 * @return string avatar URL
	 */
	public static function getDefaultAvatar(array $contact, string $size)
	{
		switch ($size) {
			case Proxy::SIZE_MICRO:
				$avatar['size'] = 48;
				$default = self::DEFAULT_AVATAR_MICRO;
				break;

			case Proxy::SIZE_THUMB:
				$avatar['size'] = 80;
				$default = self::DEFAULT_AVATAR_THUMB;
				break;

			case Proxy::SIZE_SMALL:
			default:
				$avatar['size'] = 300;
				$default = self::DEFAULT_AVATAR_PHOTO;
				break;
		}

		if (!DI::config()->get('system', 'remote_avatar_lookup')) {
			return DI::baseUrl() . $default;
		}

		if (!empty($contact['xmpp'])) {
			$avatar['email'] = $contact['xmpp'];
		} elseif (!empty($contact['addr'])) {
			$avatar['email'] = $contact['addr'];
		} elseif (!empty($contact['url'])) {
			$avatar['email'] = $contact['url'];
		} else {
			return DI::baseUrl() . $default;
		}

		$avatar['url'] = '';
		$avatar['success'] = false;

		Hook::callAll('avatar_lookup', $avatar);

		if ($avatar['success'] && !empty($avatar['url'])) {
			return $avatar['url'];
		}

		return DI::baseUrl() . $default;
	}

	/**
	 * Get avatar link for given contact id
	 *
	 * @param integer $cid     contact id
	 * @param string  $size    One of the Proxy::SIZE_* constants
	 * @param string  $updated Contact update date
	 * @return string avatar link
	 */
	public static function getAvatarUrlForId(int $cid, string $size = '', string $updated = '', string $guid = ''):string
	{
		// We have to fetch the "updated" variable when it wasn't provided
		// The parameter can be provided to improve performance
		if (empty($updated) || empty($guid)) {
			$account = DBA::selectFirst('account-user-view', ['updated', 'guid'], ['id' => $cid]);
			$updated = $account['updated'] ?? '';
			$guid = $account['guid'] ?? '';
		}

		$guid = urlencode($guid);

		$url = DI::baseUrl() . '/photo/contact/';
		switch ($size) {
			case Proxy::SIZE_MICRO:
				$url .= Proxy::PIXEL_MICRO . '/';
				break;
			case Proxy::SIZE_THUMB:
				$url .= Proxy::PIXEL_THUMB . '/';
				break;
			case Proxy::SIZE_SMALL:
				$url .= Proxy::PIXEL_SMALL . '/';
				break;
			case Proxy::SIZE_MEDIUM:
				$url .= Proxy::PIXEL_MEDIUM . '/';
				break;
			case Proxy::SIZE_LARGE:
				$url .= Proxy::PIXEL_LARGE . '/';
				break;
		}
		return $url . ($guid ?: $cid) . ($updated ? '?ts=' . strtotime($updated) : '');
	}

	/**
	 * Get avatar link for given contact URL
	 *
	 * @param string  $url  contact url
	 * @param integer $uid  user id
	 * @param string  $size One of the Proxy::SIZE_* constants
	 * @return string avatar link
	 */
	public static function getAvatarUrlForUrl(string $url, int $uid, string $size = ''):string
	{
		$condition = ["`nurl` = ? AND ((`uid` = ? AND `network` IN (?, ?)) OR `uid` = ?)",
			Strings::normaliseLink($url), $uid, Protocol::FEED, Protocol::MAIL, 0];
		$contact = self::selectFirst(['id', 'updated'], $condition, ['order' => ['uid' => true]]);
		return self::getAvatarUrlForId($contact['id'] ?? 0, $size, $contact['updated'] ?? '');
	}

	/**
	 * Get header link for given contact id
	 *
	 * @param integer $cid     contact id
	 * @param string  $size    One of the Proxy::SIZE_* constants
	 * @param string  $updated Contact update date
	 * @return string header link
	 */
	public static function getHeaderUrlForId(int $cid, string $size = '', string $updated = '', string $guid = ''):string
	{
		// We have to fetch the "updated" variable when it wasn't provided
		// The parameter can be provided to improve performance
		if (empty($updated) || empty($guid)) {
			$account = DBA::selectFirst('account-user-view', ['updated', 'guid'], ['id' => $cid]);
			$updated = $account['updated'] ?? '';
			$guid = $account['guid'] ?? '';
		}

		$guid = urlencode($guid);

		$url = DI::baseUrl() . '/photo/header/';
		switch ($size) {
			case Proxy::SIZE_MICRO:
				$url .= Proxy::PIXEL_MICRO . '/';
				break;
			case Proxy::SIZE_THUMB:
				$url .= Proxy::PIXEL_THUMB . '/';
				break;
			case Proxy::SIZE_SMALL:
				$url .= Proxy::PIXEL_SMALL . '/';
				break;
			case Proxy::SIZE_MEDIUM:
				$url .= Proxy::PIXEL_MEDIUM . '/';
				break;
			case Proxy::SIZE_LARGE:
				$url .= Proxy::PIXEL_LARGE . '/';
				break;
		}

		return $url . ($guid ?: $cid) . ($updated ? '?ts=' . strtotime($updated) : '');
	}

	/**
	 * Updates the avatar links in a contact only if needed
	 *
	 * @param int    $cid          Contact id
	 * @param string $avatar       Link to avatar picture
	 * @param bool   $force        force picture update
	 * @param bool   $create_cache Enforces the creation of cached avatar fields
	 *
	 * @return void
	 * @throws HTTPException\InternalServerErrorException
	 * @throws HTTPException\NotFoundException
	 * @throws \ImagickException
	 */
	public static function updateAvatar(int $cid, string $avatar, bool $force = false, bool $create_cache = false)
	{
		$contact = DBA::selectFirst('contact', ['uid', 'avatar', 'photo', 'thumb', 'micro', 'xmpp', 'addr', 'nurl', 'url', 'network'],
			['id' => $cid, 'self' => false]);
		if (!DBA::isResult($contact)) {
			return;
		}

		$uid = $contact['uid'];

		// Only update the cached photo links of public contacts when they already are cached
		if (($uid == 0) && !$force && empty($contact['thumb']) && empty($contact['micro']) && !$create_cache) {
			if ($contact['avatar'] != $avatar) {
				self::update(['avatar' => $avatar], ['id' => $cid]);
				Logger::info('Only update the avatar', ['id' => $cid, 'avatar' => $avatar, 'contact' => $contact]);
			}
			return;
		}

		// User contacts use are updated through the public contacts
		if (($uid != 0) && !in_array($contact['network'], [Protocol::FEED, Protocol::MAIL])) {
			$pcid = self::getIdForURL($contact['url'], 0, false);
			if (!empty($pcid)) {
				Logger::debug('Update the private contact via the public contact', ['id' => $cid, 'uid' => $uid, 'public' => $pcid]);
				self::updateAvatar($pcid, $avatar, $force, true);
				return;
			}
		}

		$default_avatar = empty($avatar) || strpos($avatar, self::DEFAULT_AVATAR_PHOTO);

		if ($default_avatar) {
			$avatar = self::getDefaultAvatar($contact, Proxy::SIZE_SMALL);
		}

		$cache_avatar = DI::config()->get('system', 'cache_contact_avatar');

		// Local contact avatars don't need to be cached
		if ($cache_avatar && Network::isLocalLink($contact['url'])) {
			$cache_avatar = !DBA::exists('contact', ['nurl' => $contact['nurl'], 'self' => true]);
		}

		if (in_array($contact['network'], [Protocol::FEED, Protocol::MAIL]) || $cache_avatar) {
			if ($default_avatar && Proxy::isLocalImage($avatar)) {
				$fields = ['avatar' => $avatar, 'avatar-date' => DateTimeFormat::utcNow(),
					'photo' => $avatar,
					'thumb' => self::getDefaultAvatar($contact, Proxy::SIZE_THUMB),
					'micro' => self::getDefaultAvatar($contact, Proxy::SIZE_MICRO)];
				Logger::debug('Use default avatar', ['id' => $cid, 'uid' => $uid]);
			}

			// Use the data from the self account
			if (empty($fields)) {
				$local_uid = User::getIdForURL($contact['url']);
				if (!empty($local_uid)) {
					$fields = self::selectFirst(['avatar', 'avatar-date', 'photo', 'thumb', 'micro'], ['self' => true, 'uid' => $local_uid]);
					Logger::debug('Use owner data', ['id' => $cid, 'uid' => $uid, 'owner-uid' => $local_uid]);
				}
			}

			if (empty($fields)) {
				$update = ($contact['avatar'] != $avatar) || $force;

				if (!$update) {
					$data = [
						$contact['photo'] ?? '',
						$contact['thumb'] ?? '',
						$contact['micro'] ?? '',
					];

					foreach ($data as $image_uri) {
						$image_rid = Photo::ridFromURI($image_uri);
						if ($image_rid && !Photo::exists(['resource-id' => $image_rid, 'uid' => $uid])) {
							Logger::debug('Regenerating avatar', ['contact uid' => $uid, 'cid' => $cid, 'missing photo' => $image_rid, 'avatar' => $contact['avatar']]);
							$update = true;
						}
					}
				}

				if ($update) {
					$photos = Photo::importProfilePhoto($avatar, $uid, $cid, true);
					if ($photos) {
						$fields = ['avatar' => $avatar, 'photo' => $photos[0], 'thumb' => $photos[1], 'micro' => $photos[2], 'avatar-date' => DateTimeFormat::utcNow()];
						$update = !empty($fields);
						Logger::debug('Created new cached avatars', ['id' => $cid, 'uid' => $uid, 'owner-uid' => $local_uid]);
					} else {
						$update = false;
					}
				}
			} else {
				$update = ($fields['photo'] . $fields['thumb'] . $fields['micro'] != $contact['photo'] . $contact['thumb'] . $contact['micro']) || $force;
			}
		} else {
			Photo::delete(['uid' => $uid, 'contact-id' => $cid, 'photo-type' => Photo::CONTACT_AVATAR]);
			$fields = ['avatar' => $avatar, 'avatar-date' => DateTimeFormat::utcNow(),
				'photo' => '', 'thumb' => '', 'micro' => ''];
			$update = ($avatar != $contact['avatar'] . $contact['photo'] . $contact['thumb'] . $contact['micro']) || $force;
		}

		if (!$update) {
			return;
		}

		$cids = [];
		$uids = [];
		if (($uid == 0) && !in_array($contact['network'], [Protocol::FEED, Protocol::MAIL])) {
			// Collect all user contacts of the given public contact
			$personal_contacts = DBA::select('contact', ['id', 'uid'],
				["`nurl` = ? AND `id` != ? AND NOT `self`", $contact['nurl'], $cid]);
			while ($personal_contact = DBA::fetch($personal_contacts)) {
				$cids[] = $personal_contact['id'];
				$uids[] = $personal_contact['uid'];
			}
			DBA::close($personal_contacts);

			if (!empty($cids)) {
				// Delete possibly existing cached user contact avatars
				Photo::delete(['uid' => $uids, 'contact-id' => $cids, 'photo-type' => Photo::CONTACT_AVATAR]);
			}
		}

		$cids[] = $cid;
		$uids[] = $uid;
		Logger::info('Updating cached contact avatars', ['cid' => $cids, 'uid' => $uids, 'fields' => $fields]);
		self::update($fields, ['id' => $cids]);
	}

	public static function deleteContactByUrl(string $url)
	{
		// Update contact data for all users
		$condition = ['self' => false, 'nurl' => Strings::normaliseLink($url)];
		$contacts = DBA::select('contact', ['id', 'uid'], $condition);
		while ($contact = DBA::fetch($contacts)) {
			Logger::info('Deleting contact', ['id' => $contact['id'], 'uid' => $contact['uid'], 'url' => $url]);
			self::remove($contact['id']);
		}
	}

	/**
	 * Helper function for "updateFromProbe". Updates personal and public contact
	 *
	 * @param integer $id      contact id
	 * @param integer $uid     user id
	 * @param string  $old_url The previous profile URL of the contact
	 * @param string  $new_url The profile URL of the contact
	 * @param array   $fields  The fields that are updated
	 *
	 * @throws \Exception
	 */
	private static function updateContact(int $id, int $uid, string $old_url, string $new_url, array $fields)
	{
		if (!self::update($fields, ['id' => $id])) {
			Logger::info('Couldn\'t update contact.', ['id' => $id, 'fields' => $fields]);
			return;
		}

		// Search for duplicated contacts and get rid of them
		if (self::removeDuplicates(Strings::normaliseLink($new_url), $uid)) {
			return;
		}

		// Archive or unarchive the contact.
		$contact = DBA::selectFirst('contact', [], ['id' => $id]);
		if (!DBA::isResult($contact)) {
			Logger::info('Couldn\'t select contact for archival.', ['id' => $id]);
			return;
		}

		if (isset($fields['failed'])) {
			if ($fields['failed']) {
				self::markForArchival($contact);
			} else {
				self::unmarkForArchival($contact);
			}
		}

		if ($contact['uid'] != 0) {
			return;
		}

		// Update contact data for all users
		$condition = ['self' => false, 'nurl' => Strings::normaliseLink($old_url)];

		$condition['network'] = [Protocol::DFRN, Protocol::DIASPORA, Protocol::ACTIVITYPUB];
		self::update($fields, $condition);

		// We mustn't set the update fields for OStatus contacts since they are updated in OnePoll
		$condition['network'] = Protocol::OSTATUS;

		// If the contact failed, propagate the update fields to all contacts
		if (empty($fields['failed'])) {
			unset($fields['last-update']);
			unset($fields['success_update']);
			unset($fields['failure_update']);
		}

		if (empty($fields)) {
			return;
		}

		self::update($fields, $condition);
	}

	/**
	 * Remove duplicated contacts
	 *
	 * @param string  $nurl  Normalised contact url
	 * @param integer $uid   User id
	 * @return boolean
	 * @throws \Exception
	 */
	public static function removeDuplicates(string $nurl, int $uid)
	{
		$condition = ['nurl' => $nurl, 'uid' => $uid, 'self' => false, 'deleted' => false, 'network' => Protocol::FEDERATED];
		$count = DBA::count('contact', $condition);
		if ($count <= 1) {
			return false;
		}

		$first_contact = DBA::selectFirst('contact', ['id', 'network'], $condition, ['order' => ['id']]);
		if (!DBA::isResult($first_contact)) {
			// Shouldn't happen - so we handle it
			return false;
		}

		$first = $first_contact['id'];
		Logger::info('Found duplicates', ['count' => $count, 'first' => $first, 'uid' => $uid, 'nurl' => $nurl]);
		if (($uid != 0 && ($first_contact['network'] == Protocol::DFRN))) {
			// Don't handle non public DFRN duplicates by now (legacy DFRN is very special because of the key handling)
			Logger::info('Not handling non public DFRN duplicate', ['uid' => $uid, 'nurl' => $nurl]);
			return false;
		}

		// Find all duplicates
		$condition = ["`nurl` = ? AND `uid` = ? AND `id` != ? AND NOT `self` AND NOT `deleted`", $nurl, $uid, $first];
		$duplicates = DBA::select('contact', ['id', 'network'], $condition);
		while ($duplicate = DBA::fetch($duplicates)) {
			if (!in_array($duplicate['network'], Protocol::FEDERATED)) {
				continue;
			}

			Worker::add(PRIORITY_HIGH, 'MergeContact', $first, $duplicate['id'], $uid);
		}
		DBA::close($duplicates);
		Logger::info('Duplicates handled', ['uid' => $uid, 'nurl' => $nurl, 'callstack' => System::callstack(20)]);
		return true;
	}

	/**
	 * @param integer $id      contact id
	 * @param string  $network Optional network we are probing for
	 * @return boolean
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function updateFromProbe(int $id, string $network = '')
	{
		$contact = DBA::selectFirst('contact', ['uid', 'url'], ['id' => $id]);
		if (!DBA::isResult($contact)) {
			return false;
		}

		$ret = Probe::uri($contact['url'], $network, $contact['uid']);

		if ($ret['network'] == Protocol::DIASPORA) {
			FContact::updateFromProbeArray($ret);
		}

		return self::updateFromProbeArray($id, $ret);
	}

	/**
	 * @param integer $id      contact id
	 * @param array   $ret     Probed data
	 * @return boolean
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function updateFromProbeArray(int $id, array $ret)
	{
		/*
		  Warning: Never ever fetch the public key via Probe::uri and write it into the contacts.
		  This will reliably kill your communication with old Friendica contacts.
		 */

		// These fields aren't updated by this routine:
		// 'sensitive'

		$fields = ['uid', 'uri-id', 'avatar', 'header', 'name', 'nick', 'location', 'keywords', 'about', 'subscribe',
			'manually-approve', 'unsearchable', 'url', 'addr', 'batch', 'notify', 'poll', 'request', 'confirm', 'poco',
			'network', 'alias', 'baseurl', 'gsid', 'forum', 'prv', 'contact-type', 'pubkey', 'last-item', 'xmpp', 'matrix'];
		$contact = DBA::selectFirst('contact', $fields, ['id' => $id]);
		if (!DBA::isResult($contact)) {
			return false;
		}

		if (self::isLocal($ret['url'])) {
			if ($contact['uid'] == 0) {
				Logger::info('Local contacts are not updated here.');
			} else {
				self::updateFromPublicContact($id, $contact);
			}
			return true;
		}

		if (!empty($ret['account-type']) && $ret['account-type'] == User::ACCOUNT_TYPE_DELETED) {
			Logger::info('Deleted account', ['id' => $id, 'url' => $ret['url'], 'ret' => $ret]);
			self::remove($id);

			// Delete all contacts with the same URL
			self::deleteContactByUrl($ret['url']);
			return true;
		}

		$uid = $contact['uid'];
		unset($contact['uid']);

		$uriid = $contact['uri-id'];
		unset($contact['uri-id']);

		$pubkey = $contact['pubkey'];
		unset($contact['pubkey']);

		$contact['photo'] = $contact['avatar'];
		unset($contact['avatar']);

		$updated = DateTimeFormat::utcNow();

		if (Strings::normaliseLink($contact['url']) != Strings::normaliseLink($ret['url'])) {
			Logger::notice('New URL differs from old URL', ['id' => $id, 'uid' => $uid, 'old' => $contact['url'], 'new' => $ret['url']]);
			self::updateContact($id, $uid, $contact['url'], $ret['url'], ['failed' => true, 'last-update' => $updated, 'failure_update' => $updated]);
			return false;
		}

		// We must not try to update relay contacts via probe. They are no real contacts.
		// We check after the probing to be able to correct falsely detected contact types.
		if (($contact['contact-type'] == self::TYPE_RELAY) &&
			(!Strings::compareLink($ret['url'], $contact['url']) || in_array($ret['network'], [Protocol::FEED, Protocol::PHANTOM]))) {
			self::updateContact($id, $uid, $contact['url'], $contact['url'], ['failed' => false, 'last-update' => $updated, 'success_update' => $updated]);
			Logger::info('Not updating relais', ['id' => $id, 'url' => $contact['url']]);
			return true;
		}

		// If Probe::uri fails the network code will be different ("feed" or "unkn")
		if (($ret['network'] == Protocol::PHANTOM) || (($ret['network'] == Protocol::FEED) && ($ret['network'] != $contact['network']))) {
			self::updateContact($id, $uid, $contact['url'], $ret['url'], ['failed' => true, 'last-update' => $updated, 'failure_update' => $updated]);
			return false;
		}

		if (Strings::normaliseLink($ret['url']) != Strings::normaliseLink($contact['url'])) {
			$cid = self::getIdForURL($ret['url'], 0, false);
			if (!empty($cid) && ($cid != $id)) {
				Logger::notice('URL of contact changed.', ['id' => $id, 'new_id' => $cid, 'old' => $contact['url'], 'new' => $ret['url']]);
				return self::updateFromProbeArray($cid, $ret);
			}
		}

		if (isset($ret['hide']) && is_bool($ret['hide'])) {
			$ret['unsearchable'] = $ret['hide'];
		}

		if (isset($ret['account-type']) && is_int($ret['account-type'])) {
			$ret['forum'] = false;
			$ret['prv'] = false;
			$ret['contact-type'] = $ret['account-type'];
			if (($ret['contact-type'] == User::ACCOUNT_TYPE_COMMUNITY) && isset($ret['manually-approve'])) {
				$ret['forum'] = (bool)!$ret['manually-approve'];
				$ret['prv'] = (bool)!$ret['forum'];
			}
		}

		$new_pubkey = $ret['pubkey'] ?? '';

		if ($uid == 0) {
			$ret['last-item'] = Probe::getLastUpdate($ret);
			Logger::info('Fetched last item', ['id' => $id, 'probed_url' => $ret['url'], 'last-item' => $ret['last-item'], 'callstack' => System::callstack(20)]);
		}

		$update = false;
		$guid = ($ret['guid'] ?? '') ?: Item::guidFromUri($ret['url'], parse_url($ret['url'], PHP_URL_HOST));

		// make sure to not overwrite existing values with blank entries except some technical fields
		$keep = ['batch', 'notify', 'poll', 'request', 'confirm', 'poco', 'baseurl'];
		foreach ($ret as $key => $val) {
			if (!array_key_exists($key, $contact)) {
				unset($ret[$key]);
			} elseif (($contact[$key] != '') && ($val === '') && !is_bool($ret[$key]) && !in_array($key, $keep)) {
				$ret[$key] = $contact[$key];
			} elseif ($ret[$key] != $contact[$key]) {
				$update = true;
			}
		}

		if (!empty($ret['last-item']) && ($contact['last-item'] < $ret['last-item'])) {
			$update = true;
		} else {
			unset($ret['last-item']);
		}

		if (empty($uriid)) {
			$update = true;
		}

		if (!empty($ret['photo']) && ($ret['network'] != Protocol::FEED)) {
			self::updateAvatar($id, $ret['photo'], $update);
		}

		$uriid = ItemURI::insert(['uri' => $ret['url'], 'guid' => $guid]);

		if (!$update) {
			self::updateContact($id, $uid, $contact['url'], $ret['url'], ['failed' => false, 'last-update' => $updated, 'success_update' => $updated]);

			if (Contact\Relation::isDiscoverable($ret['url'])) {
				Worker::add(PRIORITY_LOW, 'ContactDiscovery', $ret['url']);
			}

			// Update the public contact
			if ($uid != 0) {
				$contact = self::getByURL($ret['url'], false, ['id']);
				if (!empty($contact['id'])) {
					self::updateFromProbeArray($contact['id'], $ret);
				}
			}

			return true;
		}

		$ret['uri-id']  = $uriid;
		$ret['nurl']    = Strings::normaliseLink($ret['url']);
		$ret['updated'] = $updated;
		$ret['failed']  = false;

		// Only fill the pubkey if it had been empty before. We have to prevent identity theft.
		if (empty($pubkey) && !empty($new_pubkey)) {
			$ret['pubkey'] = $new_pubkey;
		}

		if ((!empty($ret['addr']) && ($ret['addr'] != $contact['addr'])) || (!empty($ret['alias']) && ($ret['alias'] != $contact['alias']))) {
			$ret['uri-date'] = $updated;
		}

		if ((!empty($ret['name']) && ($ret['name'] != $contact['name'])) || (!empty($ret['nick']) && ($ret['nick'] != $contact['nick']))) {
			$ret['name-date'] = $updated;
		}

		if (($uid == 0) || in_array($ret['network'], [Protocol::DFRN, Protocol::DIASPORA, Protocol::ACTIVITYPUB])) {
			$ret['last-update'] = $updated;
			$ret['success_update'] = $updated;
		}

		unset($ret['photo']);

		self::updateContact($id, $uid, $contact['url'], $ret['url'], $ret);

		if (Contact\Relation::isDiscoverable($ret['url'])) {
			Worker::add(PRIORITY_LOW, 'ContactDiscovery', $ret['url']);
		}

		return true;
	}

	private static function updateFromPublicContact(int $id, array $contact)
	{
		$public = self::getByURL($contact['url'], false);

		$fields = [];

		foreach ($contact as $field => $value) {
			if ($field == 'uid') {
				continue;
			}
			if ($public[$field] != $value) {
				$fields[$field] = $public[$field];
			}
		}
		if (!empty($fields)) {
			self::update($fields, ['id' => $id, 'self' => false]);
			Logger::info('Updating local contact', ['id' => $id]);
		}
	}

	/**
	 * @param integer $url contact url
	 * @return integer Contact id
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function updateFromProbeByURL($url)
	{
		$id = self::getIdForURL($url);

		if (empty($id)) {
			return $id;
		}

		self::updateFromProbe($id);

		return $id;
	}

	/**
	 * Detects the communication protocol for a given contact url.
	 * This is used to detect Friendica contacts that we can communicate via AP.
	 *
	 * @param string $url contact url
	 * @param string $network Network of that contact
	 * @return string with protocol
	 */
	public static function getProtocol($url, $network)
	{
		if ($network != Protocol::DFRN) {
			return $network;
		}

		$apcontact = APContact::getByURL($url);
		if (!empty($apcontact) && !empty($apcontact['generator'])) {
			return Protocol::ACTIVITYPUB;
		} else {
			return $network;
		}
	}

	/**
	 * Takes a $uid and a url/handle and adds a new contact
	 *
	 * Currently if the contact is DFRN, interactive needs to be true, to redirect to the
	 * dfrn_request page.
	 *
	 * Otherwise this can be used to bulk add StatusNet contacts, Twitter contacts, etc.
	 *
	 * Returns an array
	 * $return['success'] boolean true if successful
	 * $return['message'] error text if success is false.
	 *
	 * Takes a $uid and a url/handle and adds a new contact
	 *
	 * @param int    $uid         The user id the contact should be created for
	 * @param string $url         The profile URL of the contact
	 * @param string $network
	 * @return array
	 * @throws HTTPException\InternalServerErrorException
	 * @throws HTTPException\NotFoundException
	 * @throws \ImagickException
	 */
	public static function createFromProbeForUser(int $uid, $url, $network = '')
	{
		$result = ['cid' => -1, 'success' => false, 'message' => ''];

		// remove ajax junk, e.g. Twitter
		$url = str_replace('/#!/', '/', $url);

		if (!Network::isUrlAllowed($url)) {
			$result['message'] = DI::l10n()->t('Disallowed profile URL.');
			return $result;
		}

		if (Network::isUrlBlocked($url)) {
			$result['message'] = DI::l10n()->t('Blocked domain');
			return $result;
		}

		if (!$url) {
			$result['message'] = DI::l10n()->t('Connect URL missing.');
			return $result;
		}

		$arr = ['url' => $url, 'contact' => []];

		Hook::callAll('follow', $arr);

		if (empty($arr)) {
			$result['message'] = DI::l10n()->t('The contact could not be added. Please check the relevant network credentials in your Settings -> Social Networks page.');
			return $result;
		}

		if (!empty($arr['contact']['name'])) {
			$probed = false;
			$ret = $arr['contact'];
		} else {
			$probed = true;
			$ret = Probe::uri($url, $network, $uid);
		}

		if (($network != '') && ($ret['network'] != $network)) {
			Logger::notice('Expected network ' . $network . ' does not match actual network ' . $ret['network']);
			return $result;
		}

		// check if we already have a contact
		// the poll url is more reliable than the profile url, as we may have
		// indirect links or webfinger links

		$condition = ['uid' => $uid, 'poll' => [$ret['poll'], Strings::normaliseLink($ret['poll'])], 'network' => $ret['network'], 'pending' => false];
		$contact = DBA::selectFirst('contact', ['id', 'rel'], $condition);
		if (!DBA::isResult($contact)) {
			$condition = ['uid' => $uid, 'nurl' => Strings::normaliseLink($ret['url']), 'network' => $ret['network'], 'pending' => false];
			$contact = DBA::selectFirst('contact', ['id', 'rel'], $condition);
		}

		$protocol = self::getProtocol($ret['url'], $ret['network']);

		// This extra param just confuses things, remove it
		if ($protocol === Protocol::DIASPORA) {
			$ret['url'] = str_replace('?absolute=true', '', $ret['url']);
		}

		// do we have enough information?
		if (empty($protocol) || ($protocol == Protocol::PHANTOM) || (empty($ret['url']) && empty($ret['addr']))) {
			$result['message'] .= DI::l10n()->t('The profile address specified does not provide adequate information.') . EOL;
			if (empty($ret['poll'])) {
				$result['message'] .= DI::l10n()->t('No compatible communication protocols or feeds were discovered.') . EOL;
			}
			if (empty($ret['name'])) {
				$result['message'] .= DI::l10n()->t('An author or name was not found.') . EOL;
			}
			if (empty($ret['url'])) {
				$result['message'] .= DI::l10n()->t('No browser URL could be matched to this address.') . EOL;
			}
			if (strpos($ret['url'], '@') !== false) {
				$result['message'] .= DI::l10n()->t('Unable to match @-style Identity Address with a known protocol or email contact.') . EOL;
				$result['message'] .= DI::l10n()->t('Use mailto: in front of address to force email check.') . EOL;
			}
			return $result;
		}

		if ($protocol === Protocol::OSTATUS && DI::config()->get('system', 'ostatus_disabled')) {
			$result['message'] .= DI::l10n()->t('The profile address specified belongs to a network which has been disabled on this site.') . EOL;
			$ret['notify'] = '';
		}

		if (!$ret['notify']) {
			$result['message'] .= DI::l10n()->t('Limited profile. This person will be unable to receive direct/personal notifications from you.') . EOL;
		}

		$writeable = ((($protocol === Protocol::OSTATUS) && ($ret['notify'])) ? 1 : 0);

		$subhub = (($protocol === Protocol::OSTATUS) ? true : false);

		$hidden = (($protocol === Protocol::MAIL) ? 1 : 0);

		$pending = false;
		if (($protocol == Protocol::ACTIVITYPUB) && isset($ret['manually-approve'])) {
			$pending = (bool)$ret['manually-approve'];
		}

		if (in_array($protocol, [Protocol::MAIL, Protocol::DIASPORA, Protocol::ACTIVITYPUB])) {
			$writeable = 1;
		}

		if (DBA::isResult($contact)) {
			// update contact
			$new_relation = (in_array($contact['rel'], [self::FOLLOWER, self::FRIEND]) ? self::FRIEND : self::SHARING);

			$fields = ['rel' => $new_relation, 'subhub' => $subhub, 'readonly' => false];
			self::update($fields, ['id' => $contact['id']]);
		} else {
			$new_relation = (in_array($protocol, [Protocol::MAIL]) ? self::FRIEND : self::SHARING);

			// create contact record
			self::insert([
				'uid'     => $uid,
				'created' => DateTimeFormat::utcNow(),
				'url'     => $ret['url'],
				'nurl'    => Strings::normaliseLink($ret['url']),
				'addr'    => $ret['addr'],
				'alias'   => $ret['alias'],
				'batch'   => $ret['batch'],
				'notify'  => $ret['notify'],
				'poll'    => $ret['poll'],
				'poco'    => $ret['poco'],
				'name'    => $ret['name'],
				'nick'    => $ret['nick'],
				'network' => $ret['network'],
				'baseurl' => $ret['baseurl'],
				'gsid'    => $ret['gsid'] ?? null,
				'protocol' => $protocol,
				'pubkey'  => $ret['pubkey'],
				'rel'     => $new_relation,
				'priority'=> $ret['priority'],
				'writable'=> $writeable,
				'hidden'  => $hidden,
				'blocked' => 0,
				'readonly'=> 0,
				'pending' => $pending,
				'subhub'  => $subhub
			]);
		}

		$contact = DBA::selectFirst('contact', [], ['url' => $ret['url'], 'network' => $ret['network'], 'uid' => $uid]);
		if (!DBA::isResult($contact)) {
			$result['message'] .= DI::l10n()->t('Unable to retrieve contact information.') . EOL;
			return $result;
		}

		$contact_id = $contact['id'];
		$result['cid'] = $contact_id;

		Group::addMember(User::getDefaultGroup($uid), $contact_id);

		// Update the avatar
		self::updateAvatar($contact_id, $ret['photo']);

		// pull feed and consume it, which should subscribe to the hub.
		if ($contact['network'] == Protocol::OSTATUS) {
			Worker::add(PRIORITY_HIGH, 'OnePoll', $contact_id, 'force');
		}

		if ($probed) {
			self::updateFromProbeArray($contact_id, $ret);
		} else {
			Worker::add(PRIORITY_HIGH, 'UpdateContact', $contact_id);
		}

		$result['success'] = Protocol::follow($uid, $contact, $protocol);

		return $result;
	}

	/**
	 * Unfollow a contact
	 *
	 * @param int $cid Public contact id
	 * @param int $uid  User ID
	 *
	 * @return bool "true" if unfollowing had been successful
	 */
	public static function unfollow(int $cid, int $uid)
	{
		$cdata = self::getPublicAndUserContactID($cid, $uid);
		if (empty($cdata['user'])) {
			return false;
		}

		$contact = self::getById($cdata['user']);

		self::removeSharer([], $contact);

		return true;
	}

	/**
	 * @param array  $importer Owner (local user) data
	 * @param array  $contact  Existing owner-specific contact data we want to expand the relationship with. Optional.
	 * @param array  $datarray An item-like array with at least the 'author-id' and 'author-url' keys for the contact. Mandatory.
	 * @param bool   $sharing  True: Contact is now sharing with Owner; False: Contact is now following Owner (default)
	 * @param string $note     Introduction additional message
	 * @return bool|null True: follow request is accepted; False: relationship is rejected; Null: relationship is pending
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function addRelationship(array $importer, array $contact, array $datarray, $sharing = false, $note = '')
	{
		// Should always be set
		if (empty($datarray['author-id'])) {
			return false;
		}

		$fields = ['url', 'name', 'nick', 'avatar', 'photo', 'network', 'blocked'];
		$pub_contact = DBA::selectFirst('contact', $fields, ['id' => $datarray['author-id']]);
		if (!DBA::isResult($pub_contact)) {
			// Should never happen
			return false;
		}

		// Contact is blocked at node-level
		if (self::isBlocked($datarray['author-id'])) {
			return false;
		}

		$url = ($datarray['author-link'] ?? '') ?: $pub_contact['url'];
		$name = $pub_contact['name'];
		$photo = ($pub_contact['avatar'] ?? '') ?: $pub_contact["photo"];
		$nick = $pub_contact['nick'];
		$network = $pub_contact['network'];

		// Ensure that we don't create a new contact when there already is one
		$cid = self::getIdForURL($url, $importer['uid']);
		if (!empty($cid)) {
			$contact = DBA::selectFirst('contact', [], ['id' => $cid]);
		}

		if (!empty($contact)) {
			if (!empty($contact['pending'])) {
				Logger::info('Pending contact request already exists.', ['url' => $url, 'uid' => $importer['uid']]);
				return null;
			}

			// Contact is blocked at user-level
			if (!empty($contact['id']) && !empty($importer['id']) &&
				Contact\User::isBlocked($contact['id'], $importer['id'])) {
				return false;
			}

			// Make sure that the existing contact isn't archived
			self::unmarkForArchival($contact);

			if (($contact['rel'] == self::SHARING)
				|| ($sharing && $contact['rel'] == self::FOLLOWER)) {
				self::update(['rel' => self::FRIEND, 'writable' => true, 'pending' => false],
						['id' => $contact['id'], 'uid' => $importer['uid']]);
			}

			// Ensure to always have the correct network type, independent from the connection request method
			self::updateFromProbe($contact['id']);

			Post\UserNotification::insertNotification($contact['id'], Activity::FOLLOW, $importer['uid']);

			return true;
		} else {
			// send email notification to owner?
			if (DBA::exists('contact', ['nurl' => Strings::normaliseLink($url), 'uid' => $importer['uid'], 'pending' => true])) {
				Logger::notice('ignoring duplicated connection request from pending contact ' . $url);
				return null;
			}

			// create contact record
			$contact_id = self::insert([
				'uid'      => $importer['uid'],
				'created'  => DateTimeFormat::utcNow(),
				'url'      => $url,
				'nurl'     => Strings::normaliseLink($url),
				'name'     => $name,
				'nick'     => $nick,
				'network'  => $network,
				'rel'      => self::FOLLOWER,
				'blocked'  => 0,
				'readonly' => 0,
				'pending'  => 1,
				'writable' => 1,
			]);

			// Ensure to always have the correct network type, independent from the connection request method
			self::updateFromProbe($contact_id);

			self::updateAvatar($contact_id, $photo, true);

			Post\UserNotification::insertNotification($contact_id, Activity::FOLLOW, $importer['uid']);

			$contact_record = DBA::selectFirst('contact', ['id', 'network', 'name', 'url', 'photo'], ['id' => $contact_id]);

			/// @TODO Encapsulate this into a function/method
			$fields = ['uid', 'username', 'email', 'page-flags', 'notify-flags', 'language'];
			$user = DBA::selectFirst('user', $fields, ['uid' => $importer['uid']]);
			if (DBA::isResult($user) && !in_array($user['page-flags'], [User::PAGE_FLAGS_SOAPBOX, User::PAGE_FLAGS_FREELOVE, User::PAGE_FLAGS_COMMUNITY])) {
				// create notification
				if (is_array($contact_record)) {
					$intro = DI::introFactory()->createNew(
						$importer['uid'],
						$contact_record['id'],
						$note
					);
					DI::intro()->save($intro);
				}

				Group::addMember(User::getDefaultGroup($importer['uid']), $contact_record['id']);

				if (($user['notify-flags'] & Notification\Type::INTRO) &&
					in_array($user['page-flags'], [User::PAGE_FLAGS_NORMAL])) {

					DI::notify()->createFromArray([
						'type'  => Notification\Type::INTRO,
						'otype' => Notification\ObjectType::INTRO,
						'verb'  => ($sharing ? Activity::FRIEND : Activity::FOLLOW),
						'uid'   => $user['uid'],
						'cid'   => $contact_record['id'],
						'link'  => DI::baseUrl() . '/notifications/intros',
					]);
				}
			} elseif (DBA::isResult($user) && in_array($user['page-flags'], [User::PAGE_FLAGS_SOAPBOX, User::PAGE_FLAGS_FREELOVE, User::PAGE_FLAGS_COMMUNITY])) {
				if (($user['page-flags'] == User::PAGE_FLAGS_FREELOVE) && ($network != Protocol::DIASPORA)) {
					self::createFromProbeForUser($importer['uid'], $url, $network);
				}

				$condition = ['uid' => $importer['uid'], 'url' => $url, 'pending' => true];
				$fields = ['pending' => false];
				if ($user['page-flags'] == User::PAGE_FLAGS_FREELOVE) {
					$fields['rel'] = self::FRIEND;
				}

				self::update($fields, $condition);

				return true;
			}
		}

		return null;
	}

	public static function removeFollower(array $contact)
	{
		if (in_array($contact['rel'] ?? [], [self::FRIEND, self::SHARING])) {
			DBA::update('contact', ['rel' => self::SHARING], ['id' => $contact['id']]);
		} elseif (!empty($contact['id'])) {
			self::remove($contact['id']);
		} else {
			DI::logger()->info('Couldn\'t remove follower because of invalid contact array', ['contact' => $contact, 'callstack' => System::callstack()]);
		}
	}

	public static function removeSharer($importer, $contact)
	{
		if (($contact['rel'] == self::FRIEND) || ($contact['rel'] == self::FOLLOWER)) {
			self::update(['rel' => self::FOLLOWER], ['id' => $contact['id']]);
		} else {
			self::remove($contact['id']);
		}
	}

	/**
	 * Create a birthday event.
	 *
	 * Update the year and the birthday.
	 */
	public static function updateBirthdays()
	{
		$condition = [
			'`bd` > ?
			AND (`contact`.`rel` = ? OR `contact`.`rel` = ?)
			AND NOT `contact`.`pending`
			AND NOT `contact`.`hidden`
			AND NOT `contact`.`blocked`
			AND NOT `contact`.`archive`
			AND NOT `contact`.`deleted`',
			DBA::NULL_DATE,
			self::SHARING,
			self::FRIEND
		];

		$contacts = DBA::select('contact', ['id', 'uid', 'name', 'url', 'bd'], $condition);

		while ($contact = DBA::fetch($contacts)) {
			Logger::notice('update_contact_birthday: ' . $contact['bd']);

			$nextbd = DateTimeFormat::utcNow('Y') . substr($contact['bd'], 4);

			if (Event::createBirthday($contact, $nextbd)) {
				// update bdyear
				DBA::update(
					'contact',
					['bdyear' => substr($nextbd, 0, 4), 'bd' => $nextbd],
					['id' => $contact['id']]
				);
			}
		}
		DBA::close($contacts);
	}

	/**
	 * Remove the unavailable contact ids from the provided list
	 *
	 * @param array $contact_ids Contact id list
	 * @return array
	 * @throws \Exception
	 */
	public static function pruneUnavailable(array $contact_ids)
	{
		if (empty($contact_ids)) {
			return [];
		}

		$contacts = self::selectToArray(['id'], [
			'id'      => $contact_ids,
			'blocked' => false,
			'pending' => false,
			'archive' => false,
		]);

		return array_column($contacts, 'id');
	}

	/**
	 * Returns a magic link to authenticate remote visitors
	 *
	 * @todo  check if the return is either a fully qualified URL or a relative path to Friendica basedir
	 *
	 * @param string $contact_url The address of the target contact profile
	 * @param string $url         An url that we will be redirected to after the authentication
	 *
	 * @return string with "redir" link
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function magicLink($contact_url, $url = '')
	{
		if (!Session::isAuthenticated()) {
			return $url ?: $contact_url; // Equivalent to: ($url != '') ? $url : $contact_url;
		}

		$contact = self::getByURL($contact_url, false);
		if (empty($contact)) {
			return $url ?: $contact_url; // Equivalent to: ($url != '') ? $url : $contact_url;
		}

		// Prevents endless loop in case only a non-public contact exists for the contact URL
		unset($contact['uid']);

		return self::magicLinkByContact($contact, $url ?: $contact_url);
	}

	/**
	 * Returns a magic link to authenticate remote visitors
	 *
	 * @param integer $cid The contact id of the target contact profile
	 * @param string  $url An url that we will be redirected to after the authentication
	 *
	 * @return string with "redir" link
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function magicLinkById($cid, $url = '')
	{
		$contact = DBA::selectFirst('contact', ['id', 'network', 'url', 'uid'], ['id' => $cid]);

		return self::magicLinkByContact($contact, $url);
	}

	/**
	 * Returns a magic link to authenticate remote visitors
	 *
	 * @param array  $contact The contact array with "uid", "network" and "url"
	 * @param string $url     An url that we will be redirected to after the authentication
	 *
	 * @return string with "redir" link
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function magicLinkByContact($contact, $url = '')
	{
		$destination = $url ?: $contact['url']; // Equivalent to ($url != '') ? $url : $contact['url'];

		if (!Session::isAuthenticated()) {
			return $destination;
		}

		// Only redirections to the same host do make sense
		if (($url != '') && (parse_url($url, PHP_URL_HOST) != parse_url($contact['url'], PHP_URL_HOST))) {
			return $url;
		}

		if (DI::pConfig()->get(local_user(), 'system', 'stay_local') && ($url == '')) {
			return 'contact/' . $contact['id'] . '/conversations';
		}

		if (!empty($contact['network']) && $contact['network'] != Protocol::DFRN) {
			return $destination;
		}

		if (empty($contact['id'])) {
			return $destination;
		}

		$redirect = 'redir/' . $contact['id'];

		if (($url != '') && !Strings::compareLink($contact['url'], $url)) {
			$redirect .= '?url=' . $url;
		}

		return $redirect;
	}

	/**
	 * Is the contact a forum?
	 *
	 * @param integer $contactid ID of the contact
	 *
	 * @return boolean "true" if it is a forum
	 */
	public static function isForum($contactid)
	{
		$fields = ['contact-type'];
		$condition = ['id' => $contactid];
		$contact = DBA::selectFirst('contact', $fields, $condition);
		if (!DBA::isResult($contact)) {
			return false;
		}

		// Is it a forum?
		return ($contact['contact-type'] == self::TYPE_COMMUNITY);
	}

	/**
	 * Can the remote contact receive private messages?
	 *
	 * @param array $contact
	 * @return bool
	 */
	public static function canReceivePrivateMessages(array $contact)
	{
		$protocol = $contact['network'] ?? $contact['protocol'] ?? Protocol::PHANTOM;
		$self = $contact['self'] ?? false;

		return in_array($protocol, [Protocol::DFRN, Protocol::DIASPORA, Protocol::ACTIVITYPUB]) && !$self;
	}

	/**
	 * Search contact table by nick or name
	 *
	 * @param string $search Name or nick
	 * @param string $mode   Search mode (e.g. "community")
	 * @param int    $uid    User ID
	 *
	 * @return array with search results
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function searchByName(string $search, string $mode = '', int $uid = 0)
	{
		if (empty($search)) {
			return [];
		}

		// check supported networks
		$networks = [Protocol::DFRN, Protocol::ACTIVITYPUB];
		if (DI::config()->get('system', 'diaspora_enabled')) {
			$networks[] = Protocol::DIASPORA;
		}

		if (!DI::config()->get('system', 'ostatus_disabled')) {
			$networks[] = Protocol::OSTATUS;
		}

		$condition = ['network' => $networks, 'failed' => false, 'deleted' => false, 'uid' => $uid];

		if ($uid == 0) {
			$condition['blocked'] = false;
		}

		// check if we search only communities or every contact
		if ($mode === 'community') {
			$condition['contact-type'] = self::TYPE_COMMUNITY;
		}

		$search .= '%';

		$condition = DBA::mergeConditions($condition,
			["(NOT `unsearchable` OR `nurl` IN (SELECT `nurl` FROM `owner-view` WHERE `publish` OR `net-publish`))
			AND (`addr` LIKE ? OR `name` LIKE ? OR `nick` LIKE ?)", $search, $search, $search]);

		$contacts = self::selectToArray([], $condition);
		return $contacts;
	}

	/**
	 * Add public contacts from an array
	 *
	 * @param array $urls
	 * @return array result "count", "added" and "updated"
	 */
	public static function addByUrls(array $urls)
	{
		$added = 0;
		$updated = 0;
		$unchanged = 0;
		$count = 0;

		foreach ($urls as $url) {
			if (empty($url) || !is_string($url)) {
				continue;
			}
			$contact = self::getByURL($url, false, ['id', 'updated']);
			if (empty($contact['id'])) {
				Worker::add(PRIORITY_LOW, 'AddContact', 0, $url);
				++$added;
			} elseif ($contact['updated'] < DateTimeFormat::utc('now -7 days')) {
				Worker::add(PRIORITY_LOW, 'UpdateContact', $contact['id']);
				++$updated;
			} else {
				++$unchanged;
			}
			++$count;
		}

		return ['count' => $count, 'added' => $added, 'updated' => $updated, 'unchanged' => $unchanged];
	}

	/**
	 * Returns a random, global contact array of the current node
	 *
	 * @return array The profile array
	 * @throws Exception
	 */
	public static function getRandomContact()
	{
		$contact = DBA::selectFirst('contact', ['id', 'network', 'url', 'uid'], [
			"`uid` = ? AND `network` = ? AND NOT `failed` AND `last-item` > ?",
			0, Protocol::DFRN, DateTimeFormat::utc('now - 1 month'),
		], ['order' => ['RAND()']]);

		if (DBA::isResult($contact)) {
			return $contact;
		}

		return [];
	}
}
