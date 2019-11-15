<?php


namespace UniteCMS\AdminBundle\DependencyInjection;

use UniteCMS\AdminBundle\AdminView\AdminViewTypeManager;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class AdminViewTypeCompilerPass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     * @throws \Exception
     */
    public function process(ContainerBuilder $container)
    {
        // always first check if the primary service is defined
        if (!$container->has(AdminViewTypeManager::class)) {
            return;
        }

        $definition = $container->findDefinition(AdminViewTypeManager::class);

        // Register schema extender
        $taggedServices = $container->findTaggedServiceIds('unite.admin_view_type');

        foreach ($taggedServices as $id => $tags) {
            $definition->addMethodCall('registerAdminViewType', [new Reference($id)]);
        }
    }
}
