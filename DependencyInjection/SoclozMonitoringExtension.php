<?php

/*
 * Copyright CloseToMe SAS 2013
 * Created by Jean-François Bustarret
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Socloz\MonitoringBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Reference;

use Socloz\MonitoringBundle\Profiler\Probe;

class SoclozMonitoringExtension extends Extension
{
    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        foreach ($config as $key => $subconfig) {
            foreach ($subconfig as $subkey => $value) {
                $container->setParameter($this->getAlias().'.'.$key.'.'.$subkey, $value);
            }
        }

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));

        foreach (array("mailer", "statsd", "exceptions", "profiler", "logger", "request_id") as $module) {
            if (isset($config[$module]['enable']) && $config[$module]['enable']) {
                $loader->load("$module.xml");
            }
        }
        if (isset($config['profiler']['enable']) && $config['profiler']['enable']) {
            $probes = array();
            foreach ($config['profiler'] as $key => $value) {
                if ($key == 'enable' || $key == "sampling" || $key == "request" || !$value) { continue; }
                $probes = array_merge($probes, $this->createProfilerProbes($key, $container));
            }
            $container->getDefinition('socloz_monitoring.profiler')
                ->replaceArgument(1, $probes);
        }
    }
    
    /**
     * Generates a probe service for a configured probe
     * 
     * @param string $name
     * @param ContainerBuilder $container
     * @return \Symfony\Component\DependencyInjection\Reference 
     */
    private function createProfilerProbes($name, ContainerBuilder $container)
    {
        $key = sprintf("socloz_monitoring.profiler.probe.definition.%s", $name);
        if ($container->hasParameter($key)) {
            $definition = $container->getParameter($key);
            return array($this->createProbeDefinition($name, Probe::TRACKER_CALLS|Probe::TRACKER_TIMING, $definition, $container));
        } else {
            return array(
                $this->createProbeDefinition($name, Probe::TRACKER_CALLS, $container->getParameter("$key.calls"), $container),
                $this->createProbeDefinition($name, Probe::TRACKER_TIMING, $container->getParameter("$key.timing"), $container)
            );
        }
    }
    
    private function createProbeDefinition($name, $tracker, $definition, ContainerBuilder $container)
    {
        $id = sprintf('socloz_monitoring.profiler.%s_%s_%s_probe', $name, $tracker&Probe::TRACKER_CALLS ? "calls" : "", $tracker&Probe::TRACKER_TIMING ? "timing" : "");

        $container
            ->setDefinition($id, new DefinitionDecorator('socloz_monitoring.profiler.probe'))
            ->replaceArgument(0, $name)
            ->replaceArgument(1, $tracker)
            ->replaceArgument(2, $definition)
            ->addTag('socloz_monitoring.profiler.probe')
        ;
            
        return new Reference($id);
    }

    public function getAlias()
    {
        return 'socloz_monitoring';
    }

}
