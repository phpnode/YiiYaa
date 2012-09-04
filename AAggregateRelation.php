<?php
/**
 * Represents a one way relationship between aggregates.
 */
class AAggregateRelation extends CComponent
{
    /**
     * @var AAggregateModel the owner of this relation
     */
    public $owner;

    /**
     * @var string the name of the relation on the owner
     */
    public $name;

    /**
     * @var string the class name of the foreign aggregate in the relationship
     */
    public $foreignClass;

    /**
     * @var string the relationship type, ether "BELONGS_TO", "HAS_MANY" or "HAS_ONE"
     */
    public $type;

    /**
     * @var string the name of the attribute on the owner that points to the id of
     * the foreign aggregate in a BELONGS_TO relationship.
     * If this attribute is specified for BELONGS_TO relations, it
     * will be used in place of the result of the finder.
     */
    public $attribute;

    /**
     * @var Callable the callback that should be executed to find the ids of the related
     * aggregates. This callback will receive the id of the owner aggregate as its first argument.
     * it should return an array of foreign aggregate ids for HAS_MANY relationships and a
     * single id for HAS_MANY (and BELONGS_TO) relations.
     */
    public $finder;

    /**
     * @var integer the number of seconds to cache the result of the finder function for.
     * If null, the result will not be cached.
     */
    public $cacheDuration;

    /**
     * @var CCacheDependency the cache dependency that should be used to validate
     * the contents of the cached finder() call.
     */
    public $cacheDependency;

    /**
     * @var AAggregateModel|AAggregateList the aggregate model or list of models
     */
    protected $_data;

    /**
     * @var array the list of aggregate ids for this relation
     */
    protected $_idList;

    /**
     * @var array the cached data for this relation
     */
    public $cachedData = array();

    /**
     * Gets the data for the relationship.
     * This could be either an aggregate instance or a list
     * of aggregates.
     * @return AAggregateModel|AAggregateList the aggregate or list of aggregates
     */
    public function getData()
    {
        if ($this->_data !== null)
            return $this->_data;

        $class = $this->foreignClass;
        $ids = $this->getIds();
        $this->_data = $class::load($ids);
        return $this->_data;
    }

    /**
     * Gets the ids in this relation
     * @return array the ids
     */
    public function getIds()
    {
        if ($this->_idList === null)
            $this->_idList = $this->findIds();
        return $this->_idList;
    }

    /**
     * Finds the ids for this relation.
     * If possible these ids will be loaded from the cache
     * @return array the ids
     */
    protected function findIds()
    {
        if ($this->validateCachedData())
            return $this->cachedData[0];

        if ($this->type === AAggregateModel::BELONGS_TO && $this->attribute !== null)
            return $this->owner->{$this->attribute};

        $ids = call_user_func($this->finder, $this->owner->id, $this->owner);

        if ($this->cacheDuration !== null) {
            $this->cachedData = array(
                $ids,
                $_SERVER['REQUEST_TIME'],
                $this->cacheDependency
            );

            $this->owner->update(false,false);
        }
        return $ids;
    }

    /**
     * Validates that the cached data is still valid
     * @return boolean true if the data is valid
     */
    protected function validateCachedData()
    {
        $data = $this->cachedData;
        if (count($data) < 1)
            return false;
        if (isset($data[1]) && $this->cacheDuration !== 0) {
            if ($data[1] >= $_SERVER['REQUEST_TIME'] + $this->cacheDuration)
                return false;
        }
        if (isset($data[2]) && $data[2] instanceof CCacheDependency)
            if ($data[2]->getHasChanged())
                return false;
        return true;
    }

    public function __sleep()
    {
        return array("cachedData");
    }

}