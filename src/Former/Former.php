<?php
/**
 * Former
 *
 * Superset of Field ; helps the user interact with it and its classes
 * Various form helpers for repopulation, rules, etc.
 */
namespace Former;

use \Underscore\Types\Arrays;
use \Underscore\Types\String;

class Former
{
  /**
   * The current environment
   * @var Illuminate\Container
   */
  protected $app;

  /**
   * The current field being worked on
   * @var Field
   */
  protected $field;

  /**
   * The current form being worked on
   * @var Form
   */
  protected $form;

  /**
   * The Populator instance
   * @var Populator
   */
  protected $populator;

  /**
   * The form's errors
   * @var Message
   */
  protected $errors;

  /**
   * An array of rules to use
   * @var array
   */
  protected $rules = array();

  /**
   * The namespace of fields
   */
  const FIELDSPACE = 'Former\Form\Fields\\';

  /**
   * Build a new Former instance
   *
   * @param Illuminate\Container\Container $app
   */
  public function __construct(\Illuminate\Container\Container $app, $populator)
  {
    $this->app = $app;
    $this->populator = $populator;
  }

  ////////////////////////////////////////////////////////////////////
  //////////////////////////// INTERFACE /////////////////////////////
  ////////////////////////////////////////////////////////////////////

  /**
   * Acts as a router that redirects methods to all of Former classes
   *
   * @param  string $method     The method called
   * @param  array  $parameters An array of parameters
   *
   * @return mixed
   */
  public function __call($method, $parameters)
  {
    // Dispatch to Form\Elements
    if ($element = Dispatch::toElements($this->app, $method, $parameters)) {
      return $element;
    }

    // Dispatch to Form\Form
    if ($form = Dispatch::toForm($this->app, $method, $parameters)) {
      return $this->form = $form;
    }

    // Dispatch to Form\Group
    if ($group = Dispatch::toGroup($this->app, $method, $parameters)) {
      return $group;
    }

    // Dispatch to Form\Actions
    if ($actions = Dispatch::toActions($this->app, $method, $parameters)) {
      return $actions;
    }

    // Checking for any supplementary classes
    $classes = explode('_', $method);
    $method  = array_pop($classes);

    // Destroy previous field instance
    $this->field = null;

    // Picking the right Class
    $callClass = $this->app['former.helpers']->getClassFromMethod($method);

    // Listing parameters
    $class = self::FIELDSPACE.$callClass;
    $this->field = new $class(
      $this->app,
      $method,
      Arrays::get($parameters, 0),
      Arrays::get($parameters, 1),
      Arrays::get($parameters, 2),
      Arrays::get($parameters, 3),
      Arrays::get($parameters, 4),
      Arrays::get($parameters, 5)
    );

    // Add framework/provided classes
    $this->field = $this->app['former.framework']->addFieldClasses($this->field, $classes);

    return $this->field;
  }

  ////////////////////////////////////////////////////////////////////
  ///////////////////////////// POPULATOR ////////////////////////////
  ////////////////////////////////////////////////////////////////////

  /**
   * Add values to populate the array
   *
   * @param mixed $values Can be an Eloquent object or an array
   */
  public function populate($values)
  {
    $this->populator = new Populator($values);
  }

  /**
   * Set the value of a particular field
   *
   * @param string $field The field's name
   * @param mixed  $value Its new value
   */
  public function populateField($field, $value)
  {
    $this->populator->setValue($field, $value);
  }

  /**
   * Get the value of a field
   *
   * @param string $field The field's name
   * @return mixed
   */
  public function getValue($field, $fallback = null)
  {
    return $this->populator->getValue($field, $fallback);
  }

  /**
   * Fetch a field value from both the new and old POST array
   *
   * @param  string $name     A field name
   * @param  string $fallback A fallback if nothing was found
   * @return string           The results
   */
  public function getPost($name, $fallback = null)
  {
    $oldValue = $this->app['request']->old($name, $fallback);

    return $this->app['request']->get($name, $oldValue);
  }

  ////////////////////////////////////////////////////////////////////
  ////////////////////////////// TOOLKIT /////////////////////////////
  ////////////////////////////////////////////////////////////////////

  /**
   * Set the errors to use for validations
   *
   * @param Message $validator The result from a validation
   */
  public function withErrors($validator = null)
  {
    // Try to get the errors form the session
    if($this->app['session']->has('errors')) $errors = $this->app['session']->get('errors');

    // If we're given a raw Validator, go fetch the errors in it
    if(method_exists($validator, 'getMessages')) $errors = $validator->getMessages();

    // If we found errors, bind them to the form
    if(isset($errors)) $this->errors = $errors;
    else $this->errors = $validator;
  }

  /**
   * Add live validation rules
   *
   * @param  array *$rules An array of Laravel rules
   */
  public function withRules()
  {
    $rules = call_user_func_array('array_merge', func_get_args());

    // Parse the rules according to Laravel conventions
    foreach ($rules as $name => $fieldRules) {
      foreach (explode('|', $fieldRules) as $rule) {

        // If we have a rule with a value
        if (($colon = strpos($rule, ':')) !== false) {
          $parameters = str_getcsv(substr($rule, $colon + 1));
       }

       // Exclude unsupported rules
       $rule = is_numeric($colon) ? substr($rule, 0, $colon) : $rule;

       // Store processed rule in Former's array
       if(!isset($parameters)) $parameters = array();
       $this->rules[$name][$rule] = $parameters;
      }
    }
  }

  /**
   * Switch the framework used by Former
   *
   * @param string $framework The name of the framework to use
   */
  public function framework($framework = null)
  {
    if (!$framework) return $this->app['former.framework']->current();

    $this->app['former.framework'] = $this->app->share(function($app) use ($framework) {
      $class = __NAMESPACE__.'\Framework\\'.$framework;

      return new $class($app);
    });
  }

  ////////////////////////////////////////////////////////////////////
  ////////////////////////////// BUILDERS ////////////////////////////
  ////////////////////////////////////////////////////////////////////

  /**
   * Closes a form
   *
   * @return string A form closing tag
   */
  public function close()
  {
    if (!$this->form) return false;

    $closed = $this->form()->close();

    // Destroy instances
    $this->form = null;
    $this->populator = new Populator;

    // Reset all values
    $this->errors = null;
    $this->rules  = null;

    return $closed;
  }

  ////////////////////////////////////////////////////////////////////
  //////////////////////////// HELPERS ///////////////////////////////
  ////////////////////////////////////////////////////////////////////

  /**
   * Get the errors for the current field
   *
   * @param  string $name A field name
   * @return string       An error message
   */
  public function getErrors($name = null)
  {
    if (!$this->field) return false;

    // Get name and translate array notation
    if(!$name) $name = $this->field->getName();
    $name = preg_replace('/\[([a-z]+)\]/', '.$1', $name);

    if ($this->errors) {
      return $this->errors->first($name);
    }
  }

  /**
   * Get a rule from the Rules array
   *
   * @param  string $name The field to fetch
   * @return array        An array of rules
   */
  public function getRules($name)
  {
    return Arrays::get($this->rules, $name);
  }

  /**
   * Returns the current Form
   *
   * @return Form
   */
  public function form()
  {
    return $this->form;
  }

  /**
   * Get the current field instance
   *
   * @return Field
   */
  public function field()
  {
    if(!$this->field) return false;

    return $this->field;
  }
}
