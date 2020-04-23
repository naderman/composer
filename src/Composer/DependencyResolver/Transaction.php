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

use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Repository\PlatformRepository;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class Transaction
{
    /**
     * @var array
     */
    protected $operations;

    /**
     * Packages present at the beginning of the transaction
     * @var array
     */
    protected $presentPackages;

    /**
     * Package set resulting from this transaction
     * @var array
     */
    protected $resultPackageMap;

    /**
     * @var array
     */
    protected $resultPackagesByName = array();

    public function __construct($presentPackages, $resultPackages)
    {
        $this->presentPackages = $presentPackages;
        $this->setResultPackageMaps($resultPackages);
        $this->operations = $this->calculateOperations();
    }

    public function getOperations()
    {
        return $this->operations;
    }

    private function setResultPackageMaps($resultPackages)
    {
        $packageSort = function (PackageInterface $a, PackageInterface $b) {
            // sort alias packages by the same name behind their non alias version
            if ($a->getName() == $b->getName() && $a instanceof AliasPackage != $b instanceof AliasPackage) {
                return $a instanceof AliasPackage ? -1 : 1;
            }
            return strcmp($b->getName(), $a->getName());
        };

        $this->resultPackageMap = array();
        foreach ($resultPackages as $package) {
            $this->resultPackageMap[spl_object_hash($package)] = $package;
            foreach ($package->getNames() as $name) {
                $this->resultPackagesByName[$name][] = $package;
            }
        }

        uasort($this->resultPackageMap, $packageSort);
        foreach ($this->resultPackagesByName as $name => $packages) {
            uasort($this->resultPackagesByName[$name], $packageSort);
        }
    }

    protected function calculateOperations()
    {
        $operations = array();

        $presentPackageMap = array();
        $removeMap = array();
        $presentAliasMap = array();
        $removeAliasMap = array();
        foreach ($this->presentPackages as $package) {
            if ($package instanceof AliasPackage) {
                $presentAliasMap[$package->getName().'::'.$package->getVersion()] = $package;
                $removeAliasMap[$package->getName().'::'.$package->getVersion()] = $package;
            } else {
                $presentPackageMap[$package->getName()] = $package;
                $removeMap[$package->getName()] = $package;
            }
        }

        $stack = $this->getRootPackages();

        $visited = array();
        $processed = array();

        while (!empty($stack)) {
            $package = array_pop($stack);

            if (isset($processed[spl_object_hash($package)])) {
                continue;
            }

            if (!isset($visited[spl_object_hash($package)])) {
                $visited[spl_object_hash($package)] = true;

                $stack[] = $package;
                if ($package instanceof AliasPackage) {
                    $stack[] = $package->getAliasOf();
                } else {
                    foreach ($package->getRequires() as $link) {
                        $possibleRequires = $this->getProvidersInResult($link);

                        foreach ($possibleRequires as $require) {
                            $stack[] = $require;
                        }
                    }
                }
            } elseif (!isset($processed[spl_object_hash($package)])) {
                $processed[spl_object_hash($package)] = true;

                if ($package instanceof AliasPackage) {
                    $aliasKey = $package->getName().'::'.$package->getVersion();
                    if (isset($presentAliasMap[$aliasKey])) {
                        unset($removeAliasMap[$aliasKey]);
                    } else {
                        $operations[] = new Operation\MarkAliasInstalledOperation($package);
                    }
                } else {
                    if (isset($presentPackageMap[$package->getName()])) {
                        $source = $presentPackageMap[$package->getName()];

                        // do we need to update?
                        // TODO different for lock?
                        if ($package->getVersion() != $presentPackageMap[$package->getName()]->getVersion() ||
                            $package->getDistReference() !== $presentPackageMap[$package->getName()]->getDistReference() ||
                            $package->getSourceReference() !== $presentPackageMap[$package->getName()]->getSourceReference()
                        ) {
                            $operations[] = new Operation\UpdateOperation($source, $package);
                        }
                        unset($removeMap[$package->getName()]);
                    } else {
                        $operations[] = new Operation\InstallOperation($package);
                        unset($removeMap[$package->getName()]);
                    }
                }
            }
        }

        foreach ($removeMap as $name => $package) {
            array_unshift($operations, new Operation\UninstallOperation($package, null));
        }
        foreach ($removeAliasMap as $nameVersion => $package) {
            $operations[] = new Operation\MarkAliasUninstalledOperation($package, null);
        }

        return $this->operations = $operations;
    }

    /**
     * Determine which packages in the result are not required by any other packages in it.
     *
     * These serve as a starting point to enumerate packages in a topological order despite potential cycles.
     * If there are packages with a cycle on the top level the package with the lowest name gets picked
     *
     * @return array
     */
    protected function getRootPackages()
    {
        $roots = $this->resultPackageMap;

        foreach ($this->resultPackageMap as $packageHash => $package) {
            if (!isset($roots[$packageHash])) {
                continue;
            }

            foreach ($package->getRequires() as $link) {
                $possibleRequires = $this->getProvidersInResult($link);

                foreach ($possibleRequires as $require) {
                    if ($require !== $package) {
                        unset($roots[spl_object_hash($require)]);
                    }
                }
            }
        }

        return $roots;
    }

    protected function getProvidersInResult(Link $link)
    {
        if (!isset($this->resultPackagesByName[$link->getTarget()])) {
            return array();
        }
        return $this->resultPackagesByName[$link->getTarget()];
    }
}
