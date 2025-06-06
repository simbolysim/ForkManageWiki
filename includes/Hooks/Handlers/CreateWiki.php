<?php

namespace Miraheze\ManageWiki\Hooks\Handlers;

use Exception;
use LocalisationCache;
use MediaWiki\Config\Config;
use Miraheze\CreateWiki\Hooks\CreateWikiCreationHook;
use Miraheze\CreateWiki\Hooks\CreateWikiDataFactoryBuilderHook;
use Miraheze\CreateWiki\Hooks\CreateWikiStatePrivateHook;
use Miraheze\CreateWiki\Hooks\CreateWikiStatePublicHook;
use Miraheze\CreateWiki\Hooks\CreateWikiTablesHook;
use Miraheze\ManageWiki\ConfigNames;
use Miraheze\ManageWiki\Helpers\DefaultPermissions;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Psr\Log\LoggerInterface;
use stdClass;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\Platform\ISQLPlatform;
use function array_diff;
use function array_diff_key;
use function array_keys;
use function array_merge;
use function in_array;
use function is_array;
use function json_decode;
use function str_replace;
use const NS_PROJECT;
use const NS_PROJECT_TALK;
use const NS_SPECIAL;

class CreateWiki implements
	CreateWikiCreationHook,
	CreateWikiDataFactoryBuilderHook,
	CreateWikiStatePrivateHook,
	CreateWikiStatePublicHook,
	CreateWikiTablesHook
{

	public function __construct(
		private readonly Config $config,
		private readonly DefaultPermissions $defaultPermissions,
		private readonly LoggerInterface $logger,
		private readonly ModuleFactory $moduleFactory,
		private readonly LocalisationCache $localisationCache
	) {
	}

	/** @inheritDoc */
	public function onCreateWikiCreation( string $dbname, bool $private ): void {
		if ( $this->moduleFactory->isEnabled( 'permissions' ) ) {
			$this->defaultPermissions->populatePermissions( $dbname, $private );
		}

		if (
			$this->config->get( ConfigNames::Extensions ) &&
			$this->config->get( ConfigNames::ExtensionsDefault )
		) {
			$mwExtensions = $this->moduleFactory->extensions( $dbname );
			$mwExtensions->add( $this->config->get( ConfigNames::ExtensionsDefault ) );
			$mwExtensions->commit();
		}

		if ( $this->moduleFactory->isEnabled( 'namespaces' ) ) {
			$mwNamespacesDefault = $this->moduleFactory->namespacesDefault();
			$defaultNamespaces = $mwNamespacesDefault->listIds();

			$mwNamespaces = $this->moduleFactory->namespaces( $dbname );
			$mwNamespaces->disableNamespaceMigrationJob();

			foreach ( $defaultNamespaces as $namespace ) {
				$mwNamespaces->modify(
					$namespace,
					$mwNamespacesDefault->list( $namespace ),
					maintainPrefix: false
				);
				$mwNamespaces->commit();
			}
		}
	}

	/** @inheritDoc */
	public function onCreateWikiDataFactoryBuilder(
		string $dbname,
		IReadableDatabase $dbr,
		array &$cacheArray
	): void {
		if ( $this->moduleFactory->isEnabled( 'settings' ) ) {
			$cacheArray['settings'] = $this->moduleFactory->settings( $dbname )->listAll();
		}

		if ( $this->moduleFactory->isEnabled( 'extensions' ) ) {
			$cacheArray['extensions'] = $this->moduleFactory->extensions( $dbname )->listNames();
		}

		// Collate NS entries and decode their entries for the array
		if ( $this->moduleFactory->isEnabled( 'namespaces' ) ) {
			$nsObjects = $dbr->newSelectQueryBuilder()
				->select( ISQLPlatform::ALL_ROWS )
				->from( 'mw_namespaces' )
				->where( [ 'ns_dbname' => $dbname ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			$metaNamespace = '';
			$metaNamespaceTalk = '';

			foreach ( $nsObjects as $ns ) {
				if ( !$ns instanceof stdClass ) {
					// Skip unexpected row
					continue;
				}

				if ( $metaNamespace !== '' && $metaNamespaceTalk !== '' ) {
					// Both found, no need to continue
					break;
				}

				$id = (int)$ns->ns_namespace_id;

				if ( $id === NS_PROJECT ) {
					$metaNamespace = $ns->ns_namespace_name;
					continue;
				}

				if ( $id === NS_PROJECT_TALK ) {
					$metaNamespaceTalk = $ns->ns_namespace_name;
				}
			}

			$lcName = [];
			$lcEN = [];

			try {
				$languageCode = $cacheArray['core']['wgLanguageCode'] ?? 'en';
				$lcName = $this->localisationCache->getItem( $languageCode, 'namespaceNames' );
				$lcName[NS_PROJECT_TALK] = str_replace( '$1',
					$lcName[NS_PROJECT] ?? $metaNamespace,
					$lcName[NS_PROJECT_TALK] ?? $metaNamespaceTalk
				);

				if ( $languageCode !== 'en' ) {
					$lcEN = $this->localisationCache->getItem( 'en', 'namespaceNames' );
				}
			} catch ( Exception $e ) {
				$this->logger->error( 'Caught exception trying to load Localisation Cache: {exception}', [
					'exception' => $e,
				] );
			}

			$additional = $this->config->get( ConfigNames::NamespacesAdditional );
			foreach ( $nsObjects as $ns ) {
				if ( !$ns instanceof stdClass ) {
					// Skip unexpected row
					continue;
				}

				$nsName = $lcName[(int)$ns->ns_namespace_id] ?? $ns->ns_namespace_name;
				$lcAlias = $lcEN[(int)$ns->ns_namespace_id] ?? null;

				$cacheArray['namespaces'][$nsName] = [
					'id' => (int)$ns->ns_namespace_id,
					'core' => (bool)$ns->ns_core,
					'searchable' => (bool)$ns->ns_searchable,
					'subpages' => (bool)$ns->ns_subpages,
					'content' => (bool)$ns->ns_content,
					'contentmodel' => $ns->ns_content_model,
					'protection' => $ns->ns_protection ?: false,
					'aliases' => array_merge(
						json_decode( str_replace( [ ' ', ':' ], '_', $ns->ns_aliases ?? '[]' ), true ),
						(array)$lcAlias
					),
					'additional' => json_decode( $ns->ns_additional ?? '[]', true ),
				];

				$nsAdditional = json_decode( $ns->ns_additional ?? '[]', true );
				foreach ( $additional as $var => $conf ) {
					$nsID = (int)$ns->ns_namespace_id;

					if ( !$this->isAdditionalSettingForNamespace( $conf, $nsID ) ) {
						continue;
					}

					if ( isset( $nsAdditional[$var] ) ) {
						$val = $nsAdditional[$var];
					} elseif ( is_array( $conf['overridedefault'] ) ) {
						$val = $conf['overridedefault'][$nsID]
							?? $conf['overridedefault']['default']
							?? null;

						if ( $val === null ) {
							// Skip if no fallback exists
							continue;
						}
					} else {
						$val = $conf['overridedefault'];
					}

					if ( $val ) {
						$this->setNamespaceSettingCache( $cacheArray, $nsID, $var, $val, $conf );
						continue;
					}

					if ( empty( $conf['constant'] ) && empty( $cacheArray['settings'][$var] ) ) {
						$cacheArray['settings'][$var] = [];
					}
				}
			}

			// Search for and apply overridedefaults to NS_SPECIAL
			// Notably, we do not apply 'default' overridedefault to NS_SPECIAL
			// It must exist as it's own key in overridedefault
			foreach ( $additional as $var => $conf ) {
				if (
					( $conf['overridedefault'][NS_SPECIAL] ?? false ) &&
					$this->isAdditionalSettingForNamespace( $conf, NS_SPECIAL )
				) {
					$val = $conf['overridedefault'][NS_SPECIAL];
					$this->setNamespaceSettingCache( $cacheArray, NS_SPECIAL, $var, $val, $conf );
				}
			}
		}

		// Same as NS above but for permissions
		if ( $this->moduleFactory->isEnabled( 'permissions' ) ) {
			$permObjects = $dbr->newSelectQueryBuilder()
				->select( ISQLPlatform::ALL_ROWS )
				->from( 'mw_permissions' )
				->where( [ 'perm_dbname' => $dbname ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			$additionalRights = $this->config->get( ConfigNames::PermissionsAdditionalRights );
			$additionalAddGroups = $this->config->get( ConfigNames::PermissionsAdditionalAddGroups );
			$additionalRemoveGroups = $this->config->get( ConfigNames::PermissionsAdditionalRemoveGroups );

			foreach ( $permObjects as $perm ) {
				if ( !$perm instanceof stdClass ) {
					// Skip unexpected row
					continue;
				}

				$addPerms = [];
				$removePerms = [];

				foreach ( $additionalRights[$perm->perm_group] ?? [] as $right => $bool ) {
					if ( $bool ) {
						$addPerms[] = $right;
						continue;
					}

					if ( $bool === false ) {
						$removePerms[] = $right;
					}
				}

				$permissions = array_merge( json_decode( $perm->perm_permissions ?? '[]', true ), $addPerms );
				$filteredPermissions = array_diff( $permissions, $removePerms );

				$cacheArray['permissions'][$perm->perm_group] = [
					'permissions' => $filteredPermissions,
					'addgroups' => array_merge(
						json_decode( $perm->perm_addgroups ?? '[]', true ),
						$additionalAddGroups[$perm->perm_group] ?? []
					),
					'removegroups' => array_merge(
						json_decode( $perm->perm_removegroups ?? '[]', true ),
						$additionalRemoveGroups[$perm->perm_group] ?? []
					),
					'addself' => json_decode( $perm->perm_addgroupstoself ?? '[]', true ),
					'removeself' => json_decode( $perm->perm_removegroupsfromself ?? '[]', true ),
					'autopromote' => json_decode( $perm->perm_autopromote ?? '[]', true ),
				];
			}

			$diffKeys = array_keys(
				array_diff_key( $additionalRights, $cacheArray['permissions'] ?? [] )
			);

			foreach ( $diffKeys as $missingKey ) {
				$missingPermissions = [];

				foreach ( $additionalRights[$missingKey] as $right => $bool ) {
					if ( $bool ) {
						$missingPermissions[] = $right;
					}
				}

				$cacheArray['permissions'][$missingKey] = [
					'permissions' => $missingPermissions,
					'addgroups' => $additionalAddGroups[$missingKey] ?? [],
					'removegroups' => $additionalRemoveGroups[$missingKey] ?? [],
					'addself' => [],
					'removeself' => [],
					'autopromote' => [],
				];
			}
		}
	}

	/** @inheritDoc */
	public function onCreateWikiStatePrivate( string $dbname ): void {
		if ( !$this->moduleFactory->isEnabled( 'permissions' ) ) {
			return;
		}

		$this->defaultPermissions->populatePrivatePermissons( $dbname );
	}

	/** @inheritDoc */
	public function onCreateWikiStatePublic( string $dbname ): void {
		$defaultPrivateGroup = $this->config->get( ConfigNames::PermissionsDefaultPrivateGroup );
		if ( !$this->moduleFactory->isEnabled( 'permissions' ) || !$defaultPrivateGroup ) {
			return;
		}

		$mwPermissions = $this->moduleFactory->permissions( $dbname );
		// We don't need to continue if it doesn't exist
		if ( !$mwPermissions->exists( $defaultPrivateGroup ) ) {
			return;
		}

		$mwPermissions->remove( $defaultPrivateGroup );
		$mwPermissions->commit();
	}

	/** @inheritDoc */
	public function onCreateWikiTables( array &$tables ): void {
		if ( $this->moduleFactory->isEnabled( 'extensions' ) || $this->moduleFactory->isEnabled( 'settings' ) ) {
			$tables['mw_settings'] = 's_dbname';
		}

		if ( $this->moduleFactory->isEnabled( 'permissions' ) ) {
			$tables['mw_permissions'] = 'perm_dbname';
		}

		if ( $this->moduleFactory->isEnabled( 'namespaces' ) ) {
			$tables['mw_namespaces'] = 'ns_dbname';
		}
	}

	/**
	 * Adds the namespace setting for the supplied variable
	 *
	 * @param array &$cacheArray array for cache
	 * @param int $nsID namespace ID number as an integer
	 * @param string $var variable name
	 * @param mixed $val variable value
	 * @param array $varConf variable config from ConfigNames::NamespacesAdditional[$var]
	 */
	private function setNamespaceSettingCache(
		array &$cacheArray,
		int $nsID,
		string $var,
		mixed $val,
		array $varConf
	): void {
		if ( $varConf['type'] === 'check' ) {
			$cacheArray['settings'][$var][] = $nsID;
			return;
		}

		if ( $varConf['type'] === 'vestyle' ) {
			$cacheArray['settings'][$var][$nsID] = true;
			return;
		}

		if ( $varConf['constant'] ?? false ) {
			$cacheArray['settings'][$var] = str_replace( [ ' ', ':' ], '_', $val );
			return;
		}

		$cacheArray['settings'][$var][$nsID] = $val;
	}

	/**
	 * Checks if the namespace is for the additional setting given
	 *
	 * @param array $conf additional setting to check
	 * @param int $nsID namespace ID to check if the setting is allowed for
	 * @return bool Whether or not the setting is enabled for the namespace
	 */
	private function isAdditionalSettingForNamespace( array $conf, int $nsID ): bool {
		// T12237: Do not apply additional settings if the setting is not for the
		// namespace that we are on, otherwise it is very likely for the namespace to
		// not have setting set, and cause settings set before to be ignored

		$only = $conf['only'] ?? null;
		return $only === null || in_array( $nsID, (array)$only, true );
	}
}
