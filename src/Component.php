<?php

namespace Bento;

use Bento\PropStore;
use Bento\SlotStore;
use Bento\ClassnameStore;
use Bento\AttributeStore;

/**
 * Abstract Component base class for Bento.
 *
 * Handles:
 * - Prop definition with defaults, types, validation, formatting
 * - Global props (`classes`, `attributes`, `blockClass`, `id`, `resetClasses`, `resetAttributes`)
 * - Strict prop enforcement with warnings
 * - WordPress-style filters for props
 * - Auto-render on __toString
 * - Utility useAttributes(), and withProps()
 */
abstract class Component
{
    // =========================================================================
    // = Setup
    // =========================================================================

    /** @var PropStore Holds the props for the component */
    protected PropStore $props;

    /** @var SlotStore Holds the slots for the component */
    protected SlotStore $slots;

    /** @var ClassnameStore */
    protected ClassnameStore $classnames;

    /** @var AttributeStore */
    protected AttributeStore $attributes;

    /** @var bool Whether to throw warnings on unknown props */
    protected static bool $strictProps = true;

    /** @var array<string> Holds error messages */
    protected array $errors = [];

    /**
     * Constructor: Accepts initial props and calls define().
     *
     * @param array $props
     */
    public function __construct($props = [])
    {
        $this->props = new PropStore(static::class, $props);
        $this->slots = new SlotStore(static::class);
        $this->classnames = new ClassnameStore($this);
        $this->attributes = new AttributeStore($this);
        $this->setup();

        $props = $this->props->resolve();
        $slots = $this->slots->resolve();
        $classnames = $this->classnames->get();
        $attributes = $this->attributes->get();
    }

    /**
     * Returns the PropStore instance.
     *
     * @return PropStore
     */
    public function getProps(): PropStore
    {
        return $this->props;
    }

    /**
     * Returns the SlotStore instance.
     *
     * @return SlotStore
     */
    public function getSlots(): SlotStore
    {
        return $this->slots;
    }

    /**
     * Returns the ClassnameStore instance.
     *
     * @return ClassnameStore
     */
    public function getClassnames(): ClassnameStore
    {
        return $this->classnames;
    }

    /**
     * Returns the AttributeStore instance.
     *
     * @return AttributeStore
     */
    public function getAttributes(): AttributeStore
    {
        return $this->attributes;
    }

    /**
     * DX alias for registering a computed property.
     *
     * @param string $key
     * @param \Closure $fn
     */
    protected function computed($key, $fn)
    {
        $this->props->computed($key, $fn);
    }

    // =========================================================================
    // = Props
    // =========================================================================

    /**
     * Subclasses must setup their props and slots here.
     * Should call $this->setupProps(...);
     *
     * @return void
     */
    abstract protected function setup();

    /**
     * Setup the expected props.
     *
     * Example:
     *   ['title' => 'Default', 'count' => [0, 'integer', fn($v) => $v > 0]]
     *
     * @param array $definitions
     */
    protected function setupProps($definitions)
    {
        $this->props->define($$definitions);
    }

    /**
     * Access a prop by key.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function prop($key, $default = null)
    {
        return $this->props[$key] ?? $default;
    }

    /**
     * Helper to extract props and run a callback with them as scoped variables.
     *
     * @param array $keys Props keys to extract
     * @param callable $callback Function to run with extracted variables
     * @return mixed Result of callback
     */
    public function withProps($keys, $callback)
    {
        $props = $this->props->extractProps($keys);
        extract($props, EXTR_OVERWRITE);
        return $callback(...array_values($props));
    }

    // =========================================================================
    // = Slots
    // =========================================================================

    /**
     * Define slots.
     *
     * @param array $definitions Slot definitions
     * @return void
     */
    protected function defineSlots($definitions)
    {
        $this->slots->define($definitions);
    }

    /**
     * Use slot.
     *
     * @param string $name Slot name
     * @param string|callable $content Slot content
     * @return void
     */
    public function use_slot($name, $content)
    {
        $this->slots->set($name, $content);
    }

    /**
     * Check if a slot exists.
     * @param string $name
     * @return bool
     */
    public function has_slot($name)
    {
        return $this->slots->has($name);
    }

    /**
     * Check if a slot is active (exists and not empty).
     * @param string|null $name
     * @return bool
     */
    public function slot_is_active($name)
    {
        return $this->slots->is_active($name);
    }

    /**
     * Get slot content.
     *
     * @param string $name Slot name
     * @param string $fallback Fallback content
     * @return string
     */
    public function slot($name, $fallback = '')
    {
        return $this->slots->get($name, $fallback);
    }

    // =========================================================================
    // = Lifecycle
    // =========================================================================

    /**
     * Echo component as string.
     *
     * @return string
     */
    public function __toString()
    {
        try {
            return $this->renderWithLifecycle();
        } catch (\Throwable $e) {
            return "<!-- Component render error: {$e->getMessage()} -->";
        }
    }

    /**
     * Hook before render.
     * Useful for setup or prop adjustments.
     * Can be overridden in child classes.
     */
    protected function beforeRender()
    {
        // Clear computed cache before each render
        if (method_exists($this->props, 'clearComputedCache')) {
            $this->props->clearComputedCache();
        }
        // default no-op
    }

    /**
     * Hook after render.
     * Can be overridden for cleanup or logging.
     *
     * @param string $output The rendered HTML
     */
    protected function afterRender($output)
    {
        // default no-op
    }

    /**
     * Render call with lifecycle and error handling.
     * (Renamed from render() for DX; now use render() for actual rendering logic.)
     */
    public function renderWithLifecycle()
    {
        try {
            $this->beforeRender();
            $output = $this->render();
            $this->afterRender($output);
            return $output;
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Component error in " . static::class . ": " . $e->getMessage());
            }
            return render_component_error(
                static::class,
                $e->getMessage(),
                $this->props ?? [],
                $e->getTraceAsString()
            );
        }
    }

    /**
     * Renders the component using the following priority:
     * 1. WordPress filter override (`bento/component/{component}/template`)
     * 2. Child theme override at app/components/<ComponentName>/view.php
     * 3. Local view.php in component directory
     * 4. Explicit string (template path) or callable passed to $template
     *
     * @param null|string|callable $template
     * @return string
     */
    protected function render($template = null)
    {
        // 1. Allow WP filter to override template
        $componentClass = static::class;
        $componentName = strtolower((new \ReflectionClass($componentClass))->getShortName());
        $filterTag = "bento/component/{$componentName}/template";
        $filteredTemplate = apply_filters($filterTag, null, $this);

        if ($filteredTemplate) {
            if (is_callable($filteredTemplate)) {
                ob_start();
                $filteredTemplate($this);
                return ob_get_clean();
            } elseif (is_string($filteredTemplate) && file_exists($filteredTemplate)) {
                ob_start();
                include $filteredTemplate;
                return ob_get_clean();
            }
        }

        // 2. Child theme override
        $childThemePath = trailingslashit(get_stylesheet_directory()) . "app/components/{$componentName}/view.php";
        if (file_exists($childThemePath)) {
            ob_start();
            include $childThemePath;
            return ob_get_clean();
        }

        // 3. Local view.php in component directory
        $localView = __DIR__;
        // If this is a subclass, get its directory
        if ($componentClass !== self::class) {
            $reflector = new \ReflectionClass($componentClass);
            $localView = dirname($reflector->getFileName());
        }
        $localViewPath = $localView . '/view.php';
        if (file_exists($localViewPath)) {
            ob_start();
            include $localViewPath;
            return ob_get_clean();
        }

        // 4. Explicit string (template path) or callable
        if ($template) {
            if (is_callable($template)) {
                ob_start();
                $template($this);
                return ob_get_clean();
            } elseif (is_string($template) && file_exists($template)) {
                ob_start();
                include $template;
                return ob_get_clean();
            }
        }

        return "<!-- No template found for component: {$componentName} -->";
    }

    // =========================================================================
    // = Utils
    // =========================================================================

    /**
     * Helper for BEM/ABEM class name generation.
     * Proxies to \Bento\Helpers\block_class().
     *
     * @param string $element
     * @param string $block
     * @param string $modifier
     * @param array $options
     * @return string
     */
    protected function block_class($element = '', $block = '', $modifier = '', $options = [])
    {
        return $this->block_class_fn(
            $element,
            $block ?: $this->getBlockName(),
            $modifier,
            $options
        );
    }

    /**
     * Returns the block name for this component.
     * Uses the block_name prop if set, otherwise the class name.
     *
     * @return string
     */
    protected function getBlockName()
    {
        return $this->prop('block_name') ?: strtolower((new \ReflectionClass($this))->getShortName());
    }

    /**
     * Fetches the props for use in views.
     * Example: $this->useProps();
     *
     * @return void
     */
    public function useProps()
    {
        return $this->props->resolve();
    }

    /**
     * Build a map of attribute strings for component parts.
     * Accepts either an array or a closure that receives ($props, $slots).
     *
     * @param callable|array|null $parts
     * @return array
     */
    public function classnames($parts = null)
    {
        if ($parts !== null) {
            $props = $this->props ?? [];
            $slots = $this->slots ?? [];
            $partsArr = is_callable($parts) ? $parts($props, $slots) : $parts;
            return classnames($partsArr, $props, static::class);
        }
        return $this->classnames->get();
    }

    /**
    /**
     * Return attribute strings for all parts, with optional merging and autoescaping.
     * @param bool $autoescape     Escape all attribute values for HTML attribute use.
     * @return array
     */
    public function useAttributes(bool $autoescape = true)
    {
        // Get base attributes and classes
        $definedAttrs = $this->attributes->get();
        $definedClasses = $this->classnames->get();

        // Props
        $propAttributes = $this->prop('attributes', []);
        $propClasses = $this->prop('classes', []);
        $resetAttrs = $this->prop('resetAttributes', []);
        $resetClasses = $this->prop('resetClasses', []);

        // Gather all parts
        $allParts = array_unique(array_merge(
            array_keys($definedAttrs ?? []),
            array_keys($definedClasses ?? []),
            is_array($propAttributes) ? array_keys($propAttributes) : [],
            is_array($propClasses) ? array_keys($propClasses) : [],
            is_array($resetAttrs) ? array_keys($resetAttrs) : [],
            is_array($resetClasses) ? array_keys($resetClasses) : []
        ));

        $merged = [];
        foreach ($allParts as $part) {
            if (isset($resetAttrs[$part])) {
                $merged[$part] = $this->mergeAttributesAndClasses($resetAttrs[$part], $resetClasses[$part] ?? '');
                continue;
            }
            if (isset($resetClasses[$part])) {
                $merged[$part] = $this->mergeAttributesAndClasses($definedAttrs[$part] ?? '', $resetClasses[$part]);
                continue;
            }
            $merged[$part] = $this->mergeAttributesAndClasses(
                $definedAttrs[$part] ?? '',
                $definedClasses[$part] ?? '',
                is_array($propAttributes) ? ($propAttributes[$part] ?? '') : '',
                is_array($propClasses) ? ($propClasses[$part] ?? '') : ''
            );
        }

        // REST prop merging removed: only explicitly defined props are allowed.

        // Ensure id prop is always applied to root and takes precedence
        $id = $this->prop('id', '');
        if ($id && isset($merged['root'])) {
            // Remove any existing id="..." from the root attribute string
            $merged['root'] = preg_replace('/\s*id="[^"]*"/', '', $merged['root']);
            // Prepend the new id attribute (so it's first, but order doesn't matter for HTML)
            $merged['root'] = 'id="' . esc_attr($id) . '" ' . ltrim($merged['root']);
        }

        // Autoescape is handled in the global helper, so nothing more to do here

        return $merged;
    }

    /**
     * Recursively merge attributes and classes, preserving nested structure.
     * Accepts any number of sources (attrs, classes, prop attrs, prop classes, etc).
     * Only join classes into a string at the leaf level.
     *
     * @param mixed ...$sources
     * @return mixed
     */
    protected function mergeAttributesAndClasses(...$sources)
    {
        // Remove empty sources
        $sources = array_filter($sources, function ($s) {
            return $s !== '' && $s !== null && $s !== [];
        });
        if (empty($sources)) return '';

        // If all are arrays, merge recursively by key
        if (array_reduce($sources, fn($carry, $s) => $carry && is_array($s), true)) {
            $allKeys = [];
            foreach ($sources as $s) $allKeys = array_merge($allKeys, array_keys($s));
            $allKeys = array_unique($allKeys);
            $result = [];
            foreach ($allKeys as $key) {
                $children = [];
                foreach ($sources as $s) {
                    $children[] = is_array($s) && array_key_exists($key, $s) ? $s[$key] : '';
                }
                $result[$key] = $this->mergeAttributesAndClasses(...$children);
            }
            return $result;
        }

        // If any are arrays, merge recursively into each child
        foreach ($sources as $s) {
            if (is_array($s)) {
                $result = [];
                foreach ($s as $key => $val) {
                    $children = [];
                    foreach ($sources as $other) {
                        $children[] = is_array($other) && array_key_exists($key, $other) ? $other[$key] : (is_array($other) ? '' : $other);
                    }
                    $result[$key] = $this->mergeAttributesAndClasses(...$children);
                }
                return $result;
            }
        }

        // All are strings: merge as attribute string, append/merge class=""
        $attrString = '';
        $classList = [];
        foreach ($sources as $s) {
            if (preg_match('/class="([^"]*)"/', $s, $matches)) {
                $classList[] = $matches[1];
                $s = preg_replace('/class="[^"]*"/', '', $s);
            }
            $attrString .= ' ' . trim($s);
        }
        $attrString = trim(preg_replace('/\s+/', ' ', $attrString));
        $classString = trim(implode(' ', array_unique(array_filter($classList))));
        if ($classString !== '') {
            if (strpos($attrString, 'class="') === false) {
                // No class attribute yet, add it
                $attrString = trim($attrString . ' class="' . $classString . '"');
            } else {
                // Already has class attribute (shouldn't happen, but just in case)
                $attrString = preg_replace('/class="[^"]*"/', 'class="' . $classString . '"', $attrString);
            }
        }
        return trim($attrString);
    }

    /**
     * Get errors if any.
     * @return array<string>
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Build a BEM/ABEM class name.
     *
     * @param string $element   The element name (e.g., 'title').
     * @param string $block     The block name (e.g., 'card').
     * @param string $modifier  The modifier (e.g., 'large').
     * @param array $options    ['separator' => '__', 'mod_separator' => '--']
     * @return string
     */
    public function block_class_fn(
        string $element,
        string $block,
        string $modifier = '',
        array $options = []
    ) {
        $separator = $options['separator'] ?? '__';
        $mod_separator = $options['mod_separator'] ?? '--';

        if (!$block) {
            trigger_error('block_class: No block name provided.', E_USER_WARNING);
            return '';
        }

        $class = $block;
        if ($element !== '') {
            $class .= $separator . $element;
        }
        if ($modifier !== '') {
            $class .= $mod_separator . $modifier;
        }
        return $class;
    }
}
