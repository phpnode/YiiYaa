<?php
/**
 * Base class for aggregate models.
 * Aggregates consist of attributes from one or more base models,
 * mapped to virtual attributes with custom names on the aggregate.
 * Aggregates know how to load their own data using these models, as
 * well as how to load their own data from the cache.
 * Aggregates are not themselves saved to the database, instead they
 * are stored in the cache using a consistent key.
 * When a mapped attribute on a base model changes, it updates the
 * relevant field on the aggregate too.
 */
abstract class AAggregateModel extends CModel
{
    const BELONGS_TO = "BELONGS_TO";
    const HAS_ONE = "HAS_ONE";
    const HAS_MANY = "HAS_MANY";
    /**
     * @var integer the id of the aggregate
     */
    public $id;

    /**
     * The version of the aggregate.
     * This will be automatically incremented by save().
     * @var integer
     */
    public $version = 1;

    /**
     * @var boolean whether this is a new (unsaved) aggregate instance
     */
    public $isNewAggregate = true;

    /**
     * @var integer the duration to cache for, 0 means forever
     */
    public static $cacheDuration = 0;

    /**
     * @var array stores aggregates in a map for the duration of the request.
     * This prevents the same aggregate being loaded more than once per request.
     */
    protected static $_identityMap = array();

    protected static $_dependencyMap = array();

    /**
     * @var array the stored attributes in the aggregate
     */
    protected $_attributes = array();

    /**
     * @var AAggregateRelation[] the relation objects
     */
    protected $_relations;

    /**
     * Initialize the model.
     */
    public function __construct()
    {
        $defaults = $this->defaults();
        $attributeNames = $this->attributeNames();
        foreach($attributeNames as $name)
            $this->_attributes[$name] = isset($defaults[$name]) ? $defaults[$name] : null;
        $this->getRelations();
        $this->attachBehaviors($this->behaviors());
    }

    /**
     * Declares the models that are being aggregated.
     * This should be an array in the format:
     * <pre>
     *  array(
     *      "ModelName", // will load a model with the same PK as the aggregate ID
     *      "AnotherModelName" => "someField" // will load models which have the field "someField" set to the aggregate's ID
     *  )
     * </pre>
     */
    public static function models()
    {
        return array();
    }

    /**
     * Gets the mapping of attributes for this model.
     * This should be an array in the format:
     * <pre>
     *  array(
     *      "cleanedUpAttributeName" => array("ModelName", "attribute_name"),
     *      "relationAttributeName" => array("ModelName", "relationName.field_name"),
     *      "someReadOnlyAttribute" => array("modelName", "readOnlyAttributeName", true),
     *  )
     * </pre>
     */
    public static function mapping()
    {
        return array();
    }

    /**
     * Assembles the new model instances that should be
     * populated and saved when creating new aggregates.
     * @return array of models, modelName => model instance
     */
    public static function assemble()
    {
        return array();
    }

    /**
     * Returns the default values for aggregate attributes
     * e.g.
     * <pre>
     * array(
     *  "myAttribute" => 123,
     *  "otherAttribute" => "some value"
     * )
     * </pre>
     * @return array the defaults, attribute => value
     */
    public function defaults()
    {
        return array();
    }

    /**
     * Gets the transformer functions that are used to
     * manipulate data as it enters and exits the aggregate.
     * Data in the aggregate is referred to as "clean" and data
     * in the database is considered "dirty".
     * For Example:
     * <pre>
     * array(
     *  "cleanedUpBooleanAttribute" => array(
     *      function($input, $attributeName, $owner) {
     *          // this function is responsible for taking
     *          // the raw input for whatever field maps to
     *          // "cleanedUpBooleanAttribute" and transforming
     *          // it into a nice clean format we want to use
     *          // in our aggregate.
     *          if ($input == "Y")
     *              return true;
     *          return false;
     *      },
     *      function($input, $attributeName, $owner) {
     *          // this function is responsible for taking
     *          // the nice clean value of "cleanedUpBooleanAttribute"
     *          // and thoroughly soiling it into a format
     *          // the database likes
     *          if ($input)
     *              return "Y";
     *          return "N";
     *      }
     *  ),
     *  "someOtherAttribute" => function($input, $attributeName, $owner, $isDirty) {
     *      // when a function is supplied directly, it will be responsible
     *      // for transforming both "dirty" and "clean" attributes
     *      // the function can tell which type of data is being supplied by
     *      // inspecting the value of $isDirty
     *  }
     * )
     *
     * </pre>
     *
     * @return mixed
     */
    public function transformers()
    {
        return array();
    }


    /**
     * Declares the relations for the aggregate model.
     * Aggregate relations are slightly different to ActiveRecord style
     * relations, there is no MANY_MANY type relation as this functionality
     * is provided by HAS_MANY instead. There is also no STAT relation type,
     * STAT is replaced by count($aggregate->hasManyRelation) which becomes
     * a cheap operation, as no aggregate relations will be instantiated purely
     * by a count() call.
     * Except in the case of BELONGS_TO, aggregate relations are responsible
     * for specifying the method by which the resultant IDs for the relations
     * will be loaded. The result of this calculation can be cached for a given time
     * and can have a custom cache dependency.
     * Example:
     * <pre>
     * array(
     *     "hasManyRelationName" => array(
     *         self::HAS_MANY, // either HAS_MANY, BELONGS_TO or HAS_ONE
     *         "RelatedAggregateClassName", // the name of the related aggregate model
     *         "finder" => function($id, $owner) {
     *              // this function is responsible for returning a list of
     *              // ids of aggregate models to load.
     *              return array(1,2,3);
     *         },
     *         "cacheDuration" => 60, // number of seconds to cache for
     *         "cacheDependency" => new SomeCacheDependency()
     *     ),
     *     "hasOneRelationName" => array(
     *         self::HAS_ONE,
     *         "SomeRelatedAggregateClassName",
     *         "finder" => function($id, $owner) {
     *             // this function is responsible for returning
     *             // the id of an aggregate model to load
     *             return 3;
     *         },
     *         "cacheDuration" => 60, // number of seconds to cache for
     *         "cacheDependency" => new SomeCacheDependency()
     *     ),
     *     "belongsToRelationName" => array(
     *         self::BELONGS_TO,
     *         "SomeAggregateClassNameThatWeBelongTo",
     *         "attribute" => "someAttributeName", // the attribute on the aggregate model that refers to the id of the related aggregate.
     *         "finder" => function($id, $owner) {
     *             // this function is optional for belongs to relations,
     *             // it will only be used if the attribute is not specified.
     *         },
     *         // the following are only valid if attribute is not set.
     *         "cacheDuration" => 60, // number of seconds to cache for
     *         "cacheDependency" => new SomeCacheDependency()
     *     ),
     *
     * )
     * </pre>
     * @return array
     */
    public function relations()
    {
        return array();
    }

    /**
     * Gets a list of AAggregateRelation objects.
     * @return AAggregateRelation[] a list of relation objects
     */
    public function getRelations()
    {
        if ($this->_relations !== null)
            return $this->_relations;
        $this->_relations = array();
        foreach($this->relations() as $name => $config) {
            $relation = new AAggregateRelation();
            $relation->owner = $this;
            $relation->name = $name;
            $relation->type = array_shift($config);
            $relation->foreignClass = array_shift($config);
            foreach($config as $attribute => $value)
                $relation->{$attribute} = $value;
            $this->_relations[$name] = $relation;
        }
        return $this->_relations;
    }

    /**
     * Gets the related aggregate(s) for a relation with the given name
     * @param string $relationName the name of the aggregate
     * @return AAggregateModel|AAggregateList|boolean the related aggregate(s) or false if the relation couldn't be loaded
     */
    public function getRelated($relationName)
    {
        $relations = $this->getRelations();
        if (!isset($relations[$relationName]))
            return false;
        $relation = $relations[$relationName];
        return $relation->getData();
    }

    /**
     * Returns the values that should be serialized.
     * @return array the values that should be serialized
     */
    public function __sleep()
    {
        return array(
            "id", "version", "_attributes", "_relations"
        );
    }

    /**
     * Initializes the object after unserialization
     */
    public function __wakeup()
    {
        $cached = $this->_relations;
        $this->_relations = null;
        foreach($this->getRelations() as $name => $value)
            $this->_relations[$name]->cachedData = $cached[$name]->cachedData;
        $this->attachBehaviors($this->behaviors());
    }
    /**
	 * PHP getter magic method.
	 * This method is overridden so that aggregate attributes can be accessed like properties.
	 * @param string $name property name
	 * @return mixed property value
	 * @see getAttribute
	 */
	public function __get($name)
	{
		if(array_key_exists($name, $this->_attributes))
			return $this->_attributes[$name];
        elseif(isset($this->_relations[$name]))
            return $this->getRelated($name);
		else
			return parent::__get($name);
	}

	/**
	 * PHP setter magic method.
	 * This method is overridden so that aggregate attributes can be accessed like properties.
	 * @param string $name property name
	 * @param mixed $value property value
	 */
	public function __set($name,$value)
	{
		if($this->setAttribute($name,$value)===false)
		{
			parent::__set($name,$value);
		}
	}

	/**
	 * Checks if a property value is null.
	 * This method overrides the parent implementation by checking
	 * if the named attribute is null or not.
	 * @param string $name the property name or the event name
	 * @return boolean whether the property value is null
	 */
	public function __isset($name)
	{
		if(isset($this->_attributes[$name]))
			return true;
        elseif(isset($this->_relations[$name]))
            return true;
		else
			return parent::__isset($name);
	}

	/**
	 * Sets a component property to be null.
	 * This method overrides the parent implementation by clearing
	 * the specified attribute value.
	 * @param string $name the property name or the event name
	 */
	public function __unset($name)
	{
		if($this->hasAttribute($name))
			unset($this->_attributes[$name]);
		else
			parent::__unset($name);
	}

    /**
	 * Checks whether this aggregate has the named attribute
	 * @param string $name attribute name
	 * @return boolean whether this AR has the named attribute (table column).
	 */
	public function hasAttribute($name)
	{
		$mapping = static::mapping();
        return isset($mapping[$name]);
	}

	/**
	 * Returns the named attribute value.
	 * You may also use $this->AttributeName to obtain the attribute value.
	 * @param string $name the attribute name
	 * @return mixed the attribute value. Null if the attribute is not set or does not exist.
	 * @see hasAttribute
	 */
	public function getAttribute($name)
	{
		if(property_exists($this,$name))
			return $this->$name;
		else if(isset($this->_attributes[$name]))
			return $this->_attributes[$name];
	}

	/**
	 * Sets the named attribute value.
	 * You may also use $this->AttributeName to set the attribute value.
	 * @param string $name the attribute name
	 * @param mixed $value the attribute value.
	 * @return boolean whether the attribute exists and the assignment is conducted successfully
	 * @see hasAttribute
	 */
	public function setAttribute($name,$value)
	{
		if(property_exists($this,$name))
			$this->$name=$value;
		else if($this->hasAttribute($name))
			$this->_attributes[$name]=$value;
		else
			return false;
		return true;
	}

    /**
	 * Returns all aggregate attribute values.
	 * @param mixed $names names of attributes whose value needs to be returned.
	 * If this is true (default), then all attribute values will be returned, including
	 * those that are not loaded from DB (null will be returned for those attributes).
	 * If this is null, all attributes except those that are not loaded from DB will be returned.
	 * @return array attribute values indexed by attribute names.
	 */
	public function getAttributes($names=true)
	{
		$attributes=$this->_attributes;
		foreach($this->attributeNames() as $name)
		{
			if(property_exists($this,$name))
				$attributes[$name]=$this->$name;
			else if($names===true && !isset($attributes[$name]))
				$attributes[$name]=null;
		}
		if(is_array($names))
		{
			$attrs=array();
			foreach($names as $name)
			{
				if(property_exists($this,$name))
					$attrs[$name]=$this->$name;
				else
					$attrs[$name]=isset($attributes[$name])?$attributes[$name]:null;
			}
			return $attrs;
		}
		else
			return $attributes;
	}
    /**
     * Returns the list of attribute names of the model.
     * @return array list of attribute names.
     */
    public function attributeNames()
    {
        $names = array_keys(static::mapping());
        if (!in_array("id", $names))
            array_unshift($names, "id");
        if (!in_array("version", $names))
            $names[] = "version";
        return $names;
    }


    /**
     * Loads an aggregate or list of aggregates based on ID.
     * @param integer|array $id the id or array of ids to load
     * @param bool $skipIdentityMap whether to skip the identity map and load straight from the cache
     * @param bool $refresh whether for force getting fresh data or not
     * @return AAggregateModel|AAggregateModel[] the model or list of models. False if no aggregate could be found.
     */
    public static function load($id, $skipIdentityMap = false, $refresh = false)
    {
        if (is_array($id) || $id instanceof Traversable) {
            return new AAggregateList(get_called_class(), $id);
        }
        if (!$refresh && $loaded = self::loadFromCache($id, $skipIdentityMap))
            return $loaded;

        if (($loaded = self::loadFromDb($id, $skipIdentityMap)) === false)
            return false;
        self::saveToCache($loaded,$skipIdentityMap);
        return $loaded;
    }

    /**
     * Loads an aggregate from the cache
     * @param integer $id the id of the aggregate to load
     * @param bool $skipIdentityMap whether to skip the identity map or not
     * @return AAggregateModel|boolean the loaded model, or false
     */
    protected static function loadFromCache($id, $skipIdentityMap = false)
    {
        $calledClass = get_called_class();
        if (!$skipIdentityMap && isset(self::$_identityMap[$calledClass][$id]))
            return self::$_identityMap[$calledClass][$id];
        $cacheKey = static::buildCacheKey($id);
        if (!is_object($loaded = Yii::app()->getCache()->get($cacheKey)))
            return false;
        $loaded->isNewAggregate = false;
        if (!$skipIdentityMap)
            self::$_identityMap[$calledClass][$id] = $loaded;
        return $loaded;
    }

    /**
     * Loads an aggregate from the database
     * @param integer $id the id of the aggregate to load
     * @param bool $skipIdentityMap whether to skip the identity map or not
     * @return AAggregateModel|bool the loaded aggregate, or false if none was found
     */
    protected static function loadFromDb($id, $skipIdentityMap = false)
    {
        $calledClass = get_called_class();
        $mapping = static::mapping();
        $loaded = new static;
        $models = static::findModels($id);
        if ($models === false)
            return false;
        $loaded->isNewAggregate = false;
        $loaded->populateAggregate($models, $mapping);
        if (!$skipIdentityMap)
            self::$_identityMap[$calledClass][$id] = $loaded;
        return $loaded;
    }

    /**
     * Finds the models for an aggregate with the given id
     * @param integer $id the aggregate id
     * @param boolean $enforceRequired whether to return false if a required model cannot be found
     * @return array|boolean the loaded models modelClass => model, or false if enforceRequired is true and an aggregate is missing
     */
    public static function findModels($id, $enforceRequired = true)
    {
        $models = array();
        foreach(static::models() as $modelClass => $fieldName) {
            $required = false;
            if (is_numeric($modelClass)) {
                $required = true;
                $modelClass = $fieldName;
                $fieldName = $modelClass::model()->getTableSchema()->primaryKey;
            }
            $model = $modelClass::model()->findByAttributes(array(
                $fieldName => $id
            ));
            if ($enforceRequired && $required && !is_object($model))
                return false;
            $models[$modelClass] = $model;
        }
        return $models;
    }

    /**
     * Saves the aggregate model to the cache.
     * @param bool $runValidation whether to run validation before saving
     * @return bool whether the aggregate saved successfully or not.
     */
    public function save($runValidation = true) {
        if ($runValidation && !$this->validate())
            return false;
        $this->update();
        return true;
    }

    protected static function saveToDb(AAggregateModel $aggregate)
    {
        $assembled = $aggregate->assemble();
        if ($aggregate->isNewAggregate)
            $models = $assembled;
        else {
            $models = static::findModels($aggregate->id,false);
            foreach($models as $className => $model)
                if ($model === false)
                    $models[$className] = $assembled[$className];
        }
        $modelList = static::models();
        $mapping = static::mapping();
        $aggregate->populateModels($models, $mapping);
        $transformers = $aggregate->transformers();
        foreach($models as $modelClass => $model) {
            if (!is_object($model))
                return false;
            if ($aggregate->id !== null && isset($modelList[$modelClass]))
                $model->{$modelList[$modelClass]} = $aggregate->id;
            if (!$model->save())
                return false;
            foreach($mapping as $name => $config) {
                if ($config[0] !== $modelClass)
                    continue;
                $pointer = $config[1];
                $resolved = $aggregate->resolveAttribute($model, $pointer);
                if ($resolved === false)
                    continue;
                list($reference, $attribute) = $resolved;
                $value = $reference->{$attribute};
                if (isset($transformers[$name])) {
                    if (is_array($transformers[$name]))
                        $value = $transformers[$name][0]($value, $model, $attribute);
                    else
                        $value = $transformers[$name]($value, $model, $attribute, true);
                }
                if ($value === null && isset($defaults[$name]))
                    $value = $defaults[$name];
                $aggregate->{$name} = $value;
            }
        }

        return true;
    }



    /**
     * Updates the model
     */
    public function update($incrementVersion = true, $saveToDb = true)
    {
        if ($incrementVersion)
            $this->version++;
        if ($saveToDb)
            static::saveToDb($this);
        static::saveToCache($this);
    }

    /**
     * Saves an aggregate to the cache
     * @param AAggregateModel $model the model to save
     * @param boolean $skipIdentityMap whether to skip the identity map or not
     */
    protected static function saveToCache($model, $skipIdentityMap = false)
    {
        if (!$skipIdentityMap)
            self::$_identityMap[get_class($model)][$model->id] = $model;
        Yii::app()->cache->set(static::buildCacheKey($model->id), $model, static::$cacheDuration);
    }

    /**
     * Resolves an attribute reference
     * @param Object $reference the reference object
     * @param string $name the name of the reference e.g "some.child.attribute.name"
     * @return array|boolean the resolved attribute array, or false if the attribute couldn't be resolved
     */
    protected function resolveAttribute($reference, $name)
    {
        $parts = explode(".", $name);
        $last = array_pop($parts);
        foreach($parts as $part) {
            if (!is_object($reference))
                return false;
            $reference = $reference->{$part};
        }
        if (!is_object($reference))
            return false;
        return array($reference,$last);
    }

    /**
     * Populate the aggregate based on a list of models
     * @param CActiveRecord[] $models the models
     * @param array $mapping the mapping
     */
    protected function populateAggregate($models, $mapping)
    {
        $defaults = $this->defaults();
        $transformers = $this->transformers();

        foreach($mapping as $name => $config) {
            list($modelClass, $pointer) = $config;
            $readOnly = isset($config[2]) ? $config[2] : false;
            $resolved = $this->resolveAttribute($models[$modelClass], $pointer);
            if ($resolved === false)
                continue;
            list($model, $attribute) = $resolved;
            $value = $model->{$attribute};
            if (isset($transformers[$name])) {
                if (is_array($transformers[$name]))
                    $value = $transformers[$name][0]($value, $model, $attribute);
                else
                    $value = $transformers[$name]($value, $model, $attribute, true);
            }
            if ($value === null && isset($defaults[$name]))
                $value = $defaults[$name];
            $this->{$name} = $value;
        }
    }


    /**
     * Populates a list of models based on the aggregate values
     */
    protected function populateModels($models, $mapping)
    {
        $defaults = $this->defaults();
        foreach($mapping as $name => $config) {
            list($modelClass, $pointer) = $config;
            $readOnly = isset($config[2]) ? $config[2] : false;
            if ($readOnly)
                continue;
            $resolved = $this->resolveAttribute($models[$modelClass], $pointer);
            if ($resolved === false)
                continue;
            list($model, $attribute) = $resolved; /* @var CActiveRecord $model */

            $value = $this->{$name};
            if (isset($transformers[$name])) {
                if (is_array($transformers[$name]))
                    $value = $transformers[$name][1]($value, $this, $name);
                else
                    $value = $transformers[$name]($value, $this, $name, true);
            }
            if ($value === null && isset($defaults[$name]))
                $value = $defaults[$name];
            $relationNames = array_keys($model->relations());
            if (!isset($relationNames[$attribute]) && $value != $model->{$attribute})
                $model->{$attribute} = $value;
        }
    }

    /**
     * Gets the cache key for an aggregate
     * @param integer $id the aggregate id
     * @return string the cache key
     */
    public static function buildCacheKey($id)
    {
        return "AggregateModel:".get_called_class().":".$id;
    }
}
