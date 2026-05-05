<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Mapping;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;
use T3x\StaticHtmlImporter\Domain\Model\ImportMapping;

/**
 * Loads `Resources/Private/Mapping/*.yaml` and validates each file against
 * a fixed schema before hydrating an ImportMapping.
 *
 * The schema is intentionally narrow: one cType per file, an optional
 * selector, and a map of fields with `description` plus a `type` from a small
 * allow-list. Schema violations surface as RuntimeException with a message
 * pointing at the offending file and key.
 */
final class YamlMappingLoader
{
    private const ALLOWED_FIELD_TYPES = ['string', 'html', 'int', 'date'];

    public function loadFile(string $path): ImportMapping
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Mapping file not found: %s', $path));
        }
        if (!is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Mapping file not readable: %s', $path));
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new RuntimeException(
                sprintf('Invalid YAML in %s: %s', $path, $e->getMessage()),
                0,
                $e,
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException(sprintf(
                'Mapping must be a YAML mapping, got %s in %s',
                get_debug_type($data),
                $path,
            ));
        }

        try {
            $processed = (new Processor())->process($this->treeBuilder()->buildTree(), [$data]);
        } catch (InvalidConfigurationException $e) {
            throw new RuntimeException(
                sprintf('Mapping schema violation in %s: %s', $path, $e->getMessage()),
                0,
                $e,
            );
        }

        return $this->hydrate($processed);
    }

    /**
     * Loads every *.yaml / *.yml mapping in the directory.
     *
     * @return array<string, ImportMapping> keyed by cType
     */
    public function loadDirectory(string $path): array
    {
        if (!is_dir($path)) {
            throw new InvalidArgumentException(sprintf('Mapping directory not found: %s', $path));
        }

        $files = array_merge(
            glob(rtrim($path, '/') . '/*.yaml') ?: [],
            glob(rtrim($path, '/') . '/*.yml') ?: [],
        );
        sort($files);

        $mappings = [];
        foreach ($files as $file) {
            $mapping = $this->loadFile($file);
            if (isset($mappings[$mapping->cType])) {
                throw new RuntimeException(sprintf(
                    'Duplicate cType "%s" in %s (already loaded from another file)',
                    $mapping->cType,
                    $file,
                ));
            }
            $mappings[$mapping->cType] = $mapping;
        }
        return $mappings;
    }

    private function treeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('mapping');
        $allowed = self::ALLOWED_FIELD_TYPES;

        $tb->getRootNode()
            ->children()
                ->scalarNode('cType')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('selector')
                    ->defaultNull()
                ->end()
                ->arrayNode('fields')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('description')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('type')
                                ->defaultValue('string')
                                ->validate()
                                    ->ifNotInArray($allowed)
                                    ->thenInvalid(
                                        'Field type must be one of ['
                                        . implode(', ', $allowed)
                                        . '], got %s',
                                    )
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $tb;
    }

    /**
     * @param array{cType: string, selector: string|null, fields?: array<string, array{description: string, type: string}>} $data
     */
    private function hydrate(array $data): ImportMapping
    {
        $fields = [];
        foreach ($data['fields'] ?? [] as $name => $def) {
            $fields[$name] = new FieldDefinition(
                name: (string)$name,
                description: $def['description'],
                type: $def['type'],
            );
        }

        return new ImportMapping(
            cType: $data['cType'],
            selector: $data['selector'] ?? null,
            fields: $fields,
        );
    }
}
