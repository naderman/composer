<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\DependencyResolver;

use Composer\DependencyResolver\Operation\MarkAliasUninstalledOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class LocalRepoTransaction extends Transaction
{
    public function __construct(RepositoryInterface $lockedRepository, InstalledRepositoryInterface $localRepository)
    {
        parent::__construct(
            $localRepository->getPackages(),
            $lockedRepository->getPackages()
        );

        $this->operations = $this->movePluginsToFront($this->operations);
        // TODO fix this:
        // we have to do this again here even though the calculateOperations stack code did it because moving plugins moves them before uninstalls
        $this->operations = $this->moveUninstallsToFront($this->operations);
    }

    /**
     * Workaround: if your packages depend on plugins, we must be sure
     * that those are installed / updated first; else it would lead to packages
     * being installed multiple times in different folders, when running Composer
     * twice.
     *
     * While this does not fix the root-causes of https://github.com/composer/composer/issues/1147,
     * it at least fixes the symptoms and makes usage of composer possible (again)
     * in such scenarios.
     *
     * @param  Operation\OperationInterface[] $operations
     * @return Operation\OperationInterface[] reordered operation list
     */
    private function movePluginsToFront(array $operations)
    {
        $pluginsNoDeps = array();
        $pluginsWithDeps = array();
        $pluginRequires = array();

        foreach (array_reverse($operations, true) as $idx => $op) {
            if ($op instanceof Operation\InstallOperation) {
                $package = $op->getPackage();
            } elseif ($op instanceof Operation\UpdateOperation) {
                $package = $op->getTargetPackage();
            } else {
                continue;
            }

            // is this package a plugin?
            $isPlugin = $package->getType() === 'composer-plugin' || $package->getType() === 'composer-installer';

            // is this a plugin or a dependency of a plugin?
            if ($isPlugin || count(array_intersect($package->getNames(), $pluginRequires))) {
                // get the package's requires, but filter out any platform requirements
                $requires = array_filter(array_keys($package->getRequires()), function ($req) {
                    return !preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $req);
                });

                // is this a plugin with no meaningful dependencies?
                if ($isPlugin && !count($requires)) {
                    // plugins with no dependencies go to the very front
                    array_unshift($pluginsNoDeps, $op);
                } else {
                    // capture the requirements for this package so those packages will be moved up as well
                    $pluginRequires = array_merge($pluginRequires, $requires);
                    // move the operation to the front
                    array_unshift($pluginsWithDeps, $op);
                }

                unset($operations[$idx]);
            }
        }

        return array_merge($pluginsNoDeps, $pluginsWithDeps, $operations);
    }

    /**
     * Removals of packages should be executed before installations in
     * case two packages resolve to the same path (due to custom installers)
     *
     * @param  Operation\OperationInterface[] $operations
     * @return Operation\OperationInterface[] reordered operation list
     */
    private function moveUninstallsToFront(array $operations)
    {
        $uninstOps = array();
        foreach ($operations as $idx => $op) {
            if ($op instanceof Operation\UninstallOperation || $op instanceof Operation\MarkAliasUninstalledOperation) {
                $uninstOps[] = $op;
                unset($operations[$idx]);
            }
        }

        return array_merge($uninstOps, $operations);
    }
}
