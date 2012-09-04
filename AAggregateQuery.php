<?php
/**
 * Represents an aggregate query.
 * Aggregate queries are similar to normal database queries, but instead
 * of loading models directly from the database, the query is modified to
 * only return the ids of the results. This list of ids is then used to
 * load our aggregate objects from the cache. This allows us to perform
 * an arbitary query against a table, get back a list of aggregate IDs
 * and load those aggregates automatically.
 * Aggregate queries are very well suited for caching, since they only ever
 * return a list of ids, result sets are typically very small. This also means
 * that if an attribute on an aggregate changes, we don't have to invalidate every
 * result set that contains a pointer to the aggregate, as these cached result
 * sets will usually still be relevant.
 * Example:
 * <pre>
 * // assuming we are aggregating a model called Member into an aggregate called User,
 * // find a list of 20 active users and cache it for a minute
 * $criteria = new CDbCriteria;
 * $criteria->limit = 20;
 * $users = Member::model()->active()->cache(60)->aggregate($criteria)->all();
 * // $users is now an array of User aggregate models
 * // now even if we update the details for one of the user
 * // objects in the list, we'll still be able to use the above
 * // cached query and we'll get the freshly updated user model back
 * // without having to rerun the query on the database.
 * </pre>
 */
class AAggregateQuery extends CComponent
{
    /**
     * @var string the name of the model that we are aggregating
     */
    public $modelClass;

    /**
     * @var string the name of the aggregate class
     */
    public $aggregateClass;

    /**
     * @var CDbCriteria the criteria for the query
     */
    protected $_criteria;


    /**
     * Performs the query and returns the first result directly
     * @return AAggregateModel|false the loaded aggregate, or false if none was found
     */
    public function one()
    {
        $results = $this->query();
        return $results->first();
    }

    /**
     * Performs the query and returns all the results.
     * @return AAggregateModel[] an array of aggregates, missing results will be indicated by false
     */
    public function all()
    {
        return $this->query();
    }

    /**
     * Performs a query
     * @return AAggregateModel[] the loaded models
     */
    protected function query()
    {
        $modelClass = $this->modelClass;
        $aggregateClass = $this->aggregateClass;
        $tableName = $modelClass::model()->tableName();
        $alias = $modelClass::model()->getTableAlias();
        $criteria = clone $modelClass::model()->getDbCriteria();
        $criteria->mergeWith($this->getCriteria());
        $criteria->select = $alias.".".$modelClass::model()->getTableSchema()->primaryKey;
        $db = $modelClass::model()->getDbConnection(); /* @var CDbConnection $db */
        $command = $db->getCommandBuilder()->createFindCommand($tableName, $criteria, $alias);
        $data = $command->queryColumn();
        return $aggregateClass::load($data);
    }

    /**
     * Sets the criteria for this query
     * @param CDbCriteria $criteria
     */
    public function setCriteria($criteria)
    {
        $this->_criteria = $criteria;
    }

    /**
     * Gets the criteria for this query
     * @return CDbCriteria
     */
    public function getCriteria()
    {
        if ($this->_criteria === null) {
            $this->_criteria = new CDbCriteria;
        }
        return $this->_criteria;
    }
}