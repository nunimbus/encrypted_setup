<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022, Andrew Summers
 *
 * @author Andrew Summers
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\EncryptedSetup\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Symfony\Component\Console\Input\ArgvInput;
use OC;

class Version1000Date20221023 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$server = OC::$server;

		// This has to be in a Migration package because it runs prior to any apps loading (and thus, any files being created)

		// Set the `skeletondirectory` to empty - the encryption functions don't handle these files well durring install
		$skeletonDir = $server->getConfig()->getSystemValue('skeletondirectory');
		$server->getConfig()->setSystemValue('skeletondirectory', "");

		// Enable the encryption app and load it
		$server->getAppManager()->enableApp('encryption');
		\OC_App::loadApp('encryption');

		// Enable server-side encryption
		$encryptionManager = $server->getEncryptionManager();
		$app = $server->query('OCA\Encryption\AppInfo\Application');
		$app->registerHooks($server->getConfig());
		$app->setUp($encryptionManager);
		$server->getConfig()->setAppValue('core', 'encryption_enabled', 'yes');

		// Encrypt all files
		$input = new ArgvInput();
		$output = $server->get('Symfony\Component\Console\Output\ConsoleOutput');
		$output->setVerbosity(16);
		$defaultModule = $server->get('OCA\Encryption\Crypto\EncryptAll');
		$defaultModule->encryptAll($input, $output);

		// Disable the master key
		$server->getConfig()->setAppValue('encryption', 'useMasterKey', '0');

		// Clean up and remove the app
		$server->getConfig()->setSystemValue('skeletondirectory', $skeletonDir);

		if ($skeletonDir == "") {
			$server->getConfig()->deleteSystemValue('skeletondirectory');
		}

		$appId = OC::$server->get('OC\AppFramework\App')->getAppIdForClass(get_class($this));
		$qb = OC::$server->get('OC\DB\QueryBuilder\QueryBuilder');
		$qb->delete('migrations')->where($qb->expr()->eq('app', $qb->createNamedParameter($appId)));
		$qb->execute();

		$server->getAppManager()->disableApp('encrypted_setup');
		$installer = $server->get('OC\Installer');
		$installer->removeApp('encrypted_setup');

		return $schema;
	}
}
