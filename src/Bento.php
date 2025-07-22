<?php

namespace Bento;

class Bento
{

   /**
    * The namespace for the local Bento classes.
    *
    * @var string
    */
   public static $component_namespace = 'Bento';

   /**
    * Initialize Bento.
    * `Bento::init()` should be run as part of setup.
    *
    * @param array $options Options for initialization.
    * @return void
    */
   public static function init($options = [])
   {

      // Alias the Bento class to the namespace
      \class_alias(Bento::class, 'Bento');

      // Update the namespace for component classes
      if (isset($options['namespace'])) {
         self::$component_namespace = $options['namespace'];
      }
   }

   /**
    * Render a component.
    *
    * @param string $name The name of the component class.
    * @param array $props Props for the component.
    * @param array $slots Slots for the component.
    * @param bool $echo Whether to echo the component or return it.
    * @return string|null
    */
   public static function component(
      $name,
      $props = [],
      $slots = [],
      $echo = true,
   ) {

      // Resolve namespace and class name.
      $component = self::$component_namespace . '\\' . $name;

      if (!class_exists($component)) {
         throw new \Exception("Component '$name' not found.");
      }

      // Instantiate the component.
      $component = new $component($props);

      // Setup slots.
      if (method_exists($component, 'useSlot')) {
         foreach ($slots as $slotName => $slotContent) {
            $component->useSlot($slotName, $slotContent);
         }
      }

      if ($echo) {
         echo $component;
         return null;
      }
      return $component;
   }
}
