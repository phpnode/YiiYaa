<?php
/**
 * A behavior for children of aggregate models.
 * Ensures that the aggregate model is updated whenever a
 * mapped field on the model changes.
 * The behavior also exposes an aggregate() method that allows
 * developers to perform a query using a similar syntax as normal
 * but returning the relevant aggregate model instead.
 */
class AAggregateChildBehavior extends CActiveRecordBehavior
{
    /**
     * @var string the class name of the aggregate this model is a child of
     */
    public $aggregateClass;


    /**
     * @var string the name of the attribute on the model that points to the id
     * of the aggregate model. This can either be a plain attribute name or the name
     * of a attribute on a relation, e.g. "relationName.attributeName".
     * If not set, the model's primary key will be used.
     */
    public $idAttribute;

    /**
     * @var array the mapping of model attributes names => aggregate attribute names.
     */
    protected $_mapping;

    /**
     * Holds the aggregate instance that the model belongs to
     * @var AAggregateModel
     */
    protected $_aggregate;

    /**
     * @var string the name of the model class
     */
    protected $_modelClass;

    /**
     * Sets the aggregate instance for the model
     * @param AAggregateModel $aggregate the aggregate instance
     */
    public function setAggregate($aggregate)
    {
        $this->_aggregate = $aggregate;
    }

    /**
     * Gets the aggregate instance for the model
     * @return AAggregateModel
     */
    public function getAggregate()
    {
        if ($this->_aggregate !== null)
            return $this->_aggregate;
        $aggregateClass = $this->aggregateClass;
        $idAttribute = $this->idAttribute;
        if ($idAttribute === null)
            $idAttribute = $this->getOwner()->getTableSchema()->primaryKey;

        list($reference, $attribute) = $this->resolveAttribute($this->getOwner(),$idAttribute);
        $this->_aggregate = $aggregateClass::load($reference->{$attribute});
        return $this->_aggregate;
    }

    /**
     * Performs an aggregate query.
     * This can often be used in place of the usual find methods.
     * Instead of returning the searched for models, it will return
     * the aggregates that those models belong to.
     * @return AAggregateQuery the aggregate query object
     */
    public function aggregate($criteria = null)
    {
        $query = new AAggregateQuery;
        $query->modelClass = $this->getModelClass();
        $query->aggregateClass = $this->aggregateClass;
        $owner = $this->getOwner();
        if (is_array($criteria)) {
            // this is a findByAttributes() analog
            $attributes = $criteria;
            $criteria = new CDbCriteria;
            $criteria->addColumnCondition($attributes);
        }
        elseif (is_integer($criteria)) {
            // this is a findByPk()
            $pk = $criteria;
            $criteria = new CDbCriteria;
            $criteria->condition = $owner->getTableAlias().".".$owner->getTableSchema()->primaryKey." = :aggregateId";
            $criteria->params[":aggregateId"] = $pk;
        }

        if ($criteria !== null)
            $query->setCriteria($criteria);
        return $query;
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
     * Invoked after the model is saved.
     * Updates any relevant fields on the aggregate and saves it.
     * @param CModelEvent $event the raised event
     */
    public function afterSave($event)
    {
        $aggregate = $this->getAggregate();
        if (!is_object($aggregate))
            return;
        $changed = array();
        foreach($this->getMapping() as $attribute => $aggregateAttribute) {
            if (is_numeric($attribute))
                $attribute = $aggregateAttribute;
            if ($aggregate->{$aggregateAttribute} != $event->sender->{$attribute})
                $changed[$aggregateAttribute] = $event->sender->{$attribute};
        }
        if (!count($changed))
            return;
        foreach($changed as $attribute => $value)
            $aggregate->{$attribute} = $value;
        $aggregate->update(true, false);
    }

    /**
     * Sets the mapping of model attributes to aggregate attributes
     * @param array $mapping the mapping
     */
    public function setMapping($mapping)
    {
        $this->_mapping = $mapping;
    }

    /**
     * Gets the mapping of model attributes to aggregate attributes
     * @return array
     */
    public function getMapping()
    {
        if ($this->_mapping === null) {
            $this->_mapping = array();
            $aggregateClass = $this->aggregateClass;
            $mapping = $aggregateClass::mapping();
            $owner = $this->getOwner();
            foreach($mapping as $attribute => $config) {
                if ($owner instanceof $config[0])
                    $this->_mapping[$config[1]] = $attribute;
            }
        }
        return $this->_mapping;
    }

    /**
     * @param string $modelClass
     */
    public function setModelClass($modelClass)
    {
        $this->_modelClass = $modelClass;
    }

    /**
     * @return string
     */
    public function getModelClass()
    {
        if ($this->_modelClass === null)
            $this->_modelClass = get_class($this->owner);
        return $this->_modelClass;
    }


}