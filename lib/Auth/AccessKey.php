<?php
/**
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH.
 * @license GPL-2.0
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 */

namespace OCA\FilesExternalS3\Auth;

use OCP\Files\External\Auth\AuthMechanism;
use OCP\Files\External\DefinitionParameter;
use OCP\IL10N;

/**
 * Amazon S3 access key authentication
 */
class AccessKey extends AuthMechanism {
	public const SCHEME_AMAZONS3_ACCESSKEY = 'amazons3_accesskey';

	public function __construct(IL10N $l) {
		$this
			->setIdentifier('amazons3::accesskey')
			->setScheme(self::SCHEME_AMAZONS3_ACCESSKEY)
			->setText($l->t('Access key'))
			->addParameters([
				(new DefinitionParameter('key', $l->t('Access key'))),
				(new DefinitionParameter('secret', $l->t('Secret key')))
					->setType(DefinitionParameter::VALUE_PASSWORD),
			]);
	}
}
