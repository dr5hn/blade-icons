<?php

declare(strict_types=1);

namespace BladeUI\Icons;

use BladeUI\Icons\Components\Svg as SvgComponent;
use BladeUI\Icons\Exceptions\CannotRegisterIconSet;
use BladeUI\Icons\Exceptions\SvgNotFound;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

final class Factory
{
    /** @var Filesystem */
    private $filesystem;

    /** @var string */
    private $defaultClass;

    /** @var array */
    private $sets = [];

    /** @var array */
    private $cache = [];

    public function __construct(Filesystem $filesystem, string $defaultClass = '')
    {
        $this->filesystem = $filesystem;
        $this->defaultClass = $defaultClass;
    }

    public function all(): array
    {
        return $this->sets;
    }

    /**
     * @throws CannotRegisterIconSet
     */
    public function add(string $set, array $options): self
    {
        if (! isset($options['path'])) {
            throw CannotRegisterIconSet::pathNotDefined($set);
        }

        if (! isset($options['prefix'])) {
            throw CannotRegisterIconSet::prefixNotDefined($set);
        }

        if ($collidingSet = $this->getSetByPrefix($options['prefix'])) {
            throw CannotRegisterIconSet::prefixNotUnique($set, $collidingSet);
        }

        if ($this->filesystem->missing($options['path'])) {
            throw CannotRegisterIconSet::nonExistingPath($set, $options['path']);
        }

        $this->sets[$set] = $options;

        $this->registerComponents($options);

        $this->cache = [];

        return $this;
    }

    private function registerComponents(array $options): void
    {
        foreach ($this->filesystem->allFiles($options['path']) as $file) {
            $path = array_filter(explode('/', Str::after($file->getPath(), $options['path'])));

            Blade::component(
                SvgComponent::class,
                implode('.', array_filter($path + [$file->getFilenameWithoutExtension()])),
                $options['prefix']
            );
        }
    }

    /**
     * @throws SvgNotFound
     */
    public function svg(string $name, $class = '', array $attributes = []): Svg
    {
        [$set, $name] = $this->splitSetAndName($name);

        return new Svg($name, $this->contents($set, $name), $this->formatAttributes($class, $attributes));
    }

    /**
     * @throws SvgNotFound
     */
    private function contents(string $set, string $name): string
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        if (isset($this->sets[$set])) {
            try {
                return $this->cache[$name] = $this->getSvgFromPath($name, $this->sets[$set]['path']);
            } catch (FileNotFoundException $exception) {
                //
            }
        }

        throw SvgNotFound::missing($set, $name);
    }

    private function getSvgFromPath(string $name, string $path): string
    {
        return trim($this->filesystem->get(sprintf(
            '%s/%s.svg',
            rtrim($path),
            str_replace('.', '/', $name)
        )));
    }

    private function splitSetAndName(string $name): array
    {
        $prefix = Str::before($name, '-');

        $set = $this->getSetByPrefix($prefix);

        return [$set ?? 'default', Str::after($name, '-')];
    }

    private function getSetByPrefix(string $prefix): ?string
    {
        return collect($this->sets)->where('prefix', $prefix)->keys()->first();
    }

    private function formatAttributes($class = '', array $attributes = []): array
    {
        if (is_string($class) && $class !== '') {
            $attributes['class'] = $this->buildClass($class);
        } elseif (is_array($class)) {
            $attributes = $class;
        }

        return $attributes;
    }

    private function buildClass(string $class): string
    {
        return trim(sprintf('%s %s', $this->defaultClass, $class));
    }
}
