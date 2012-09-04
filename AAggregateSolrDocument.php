<?php
/**
 * Base class for aggregate models that can be indexed in solr
 */
abstract class AAggregateSolrDocument extends AAggregateModel implements IASolrDocument
{
    /**
	 * The solr connection
	 * @var ASolrConnection
	 */
	public static $solr;

    /**
	 * The connection to solr
	 * @var ASolrConnection
	 */
	protected $_connection;
    /**
	 * Holds the solr query response after a find call
	 * @var ASolrQueryResponse
	 */
	protected $_solrResponse;

	/**
	 * The position in the search results
	 * @var integer
	 */
	protected $_position;

	/**
	 * Holds the document score
	 * @var integer
	 */
	protected $_score;

	/**
	 * The solr input document
	 * @var SolrInputDocument
	 */
	protected $_inputDocument;

    /**
	 * @var array the highlights for this record if highlighting is enabled
	 */
	private $_highlights;

    /**
	 * An array of static model instances, clas name => model
	 * @var array
	 */
	private static $_models=array();
    /**
	 * Returns the static model of the specified solr document class.
	 * The model returned is a static instance of the solr document class.
	 * It is provided for invoking class-level methods (something similar to static class methods.)
	 *
	 * EVERY derived solr document  class must override this method as follows,
	 * <pre>
	 * public static function model($className=__CLASS__)
	 * {
	 *	 return parent::model($className);
	 * }
	 * </pre>
	 *
	 * @param string $className solr document class name.
	 * @return ASolrDocument solr document model instance.
	 */
	public static function model($className = null)
	{
        if ($className === null)
            $className = get_called_class();
		if(isset(self::$_models[$className]))
			return self::$_models[$className];
		else
		{
			$model=self::$_models[$className]=new $className(null);
			return $model;
		}
	}

    /**
	 * Returns the solr connection used by solr document.
	 * By default, the "solr" application component is used as the solr connection.
	 * You may override this method if you want to use a different solr connection.
	 * @return ASolrConnection the solr connection used by solr document.
	 */
	public function getSolrConnection()
	{
		if ($this->_connection !== null) {
			return $this->_connection;
		}
		elseif(self::$solr!==null) {
			return self::$solr;
		}
		else
		{
			self::$solr=Yii::app()->solr;
			if(self::$solr instanceof IASolrConnection)
				return self::$solr;
			else
				throw new CException(Yii::t('yii','Solr Document requires a "solr" ASolrConnection application component.'));
		}
	}
	/**
	 * Sets the solr connection used by this solr document
	 * @param ASolrConnection $connection the solr connection to use for this document
	 */
	public function setSolrConnection(ASolrConnection $connection) {
		$this->_connection = $connection;
	}

    /**
     * Returns the primary key value.
     * @return mixed the primary key value. An array (column name=>column value) is returned if the primary key is composite.
     * If primary key is not defined, null will be returned.
     */
    public function getPrimaryKey()
    {
        return $this->id;
    }

    /**
     * Sets the position in the search results
     * @param integer $position
     */
    public function setPosition($position)
    {
        $this->_position = $position;
        return $this;
    }

    /**
     * Gets the position in the search results
     * @return integer the position in the search results
     */
    public function getPosition()
    {
        return $this->_position;
    }

   /**
	 * Sets the solr query response.
	 * @param ASolrQueryResponse $solrResponse the response from solr that this model belongs to
	 */
	public function setSolrResponse($solrResponse)
	{
		$this->_solrResponse = $solrResponse;
	}

	/**
	 * Gets the response from solr that this model belongs to
	 * @return ASolrQueryResponse the solr query response
	 */
	public function getSolrResponse()
	{
		return $this->_solrResponse;
	}
    /**
     * Returns the list of attribute names that should be indexed in solr.
     * @return array list of attribute names.
     */
    public function solrAttributeNames()
    {
        return self::attributeNames();
    }

    /**
     * Gets the solr input document
     * @return SolrInputDocument the solr document
     */
    public function getInputDocument()
    {
        if ($this->_inputDocument !== null) {
			return $this->_inputDocument;
		}
		$this->_inputDocument = new SolrInputDocument();
		foreach($this->solrAttributeNames() as $attribute) {
			if ($this->{$attribute} !== null) {
				if (is_array($this->{$attribute})) {
					foreach($this->{$attribute} as $value) {
						$this->_inputDocument->addField($attribute,$value);
					}
				}
				else {
					$this->_inputDocument->addField($attribute,$this->prepareAttribute($attribute));
				}
			}
		}
		return $this->_inputDocument;
    }

    /**
	 * Sets the highlights for this record
	 * @param array $highlights the highlights, attribute => highlights
	 */
	public function setHighlights($highlights)
	{
		$this->_highlights = $highlights;
	}

	/**
	 * Gets the highlights if highlighting is enabled
	 * @param string|null $attribute the attribute to get highlights for, if null all attributes will be returned
	 * @return array|boolean the highlighted results
	 */
	public function getHighlights($attribute = null)
	{
		if ($attribute === null)
			return $this->_highlights;
		if (!isset($this->_highlights[$attribute]))
			return false;
		return $this->_highlights[$attribute];
	}

    /**
     * Finds a single solr document according to the specified criteria.
     * @param ASolrCriteria $criteria solr query criteria.
     * @return ASolrDocument the document found. Null if none is found.
     */
    public function find(ASolrCriteria $criteria = null)
    {
        if ($criteria === null)
            $criteria = new ASolrCriteria;
        return $this->solrQuery($criteria,false);
    }

    /**
     * Finds multiple solr documents according to the specified criteria.
     * @param ASolrCriteria $criteria solr query criteria.
     * @return ASolrDocument[] the documents found.
     */
    public function findAll(ASolrCriteria $criteria = null)
    {
        if ($criteria === null)
            $criteria = new ASolrCriteria;
        return $this->solrQuery($criteria,true);
    }

    /**
	 * Returns the number of documents matching specified criteria.
	 * @param ASolrCriteria $criteria solr query criteria.
	 * @return integer the number of rows found
	 */
	public function count(ASolrCriteria $criteria = null)
	{
		if ($criteria === null) {
			$criteria = new ASolrCriteria();
		}
		return $this->getSolrConnection()->count($criteria);
	}

    /**
	 * Performs the actual solr query and populates the solr document objects with the query result.
	 * This method is mainly internally used by other solr document query methods.
	 * @param ASolrCriteria $criteria the query criteria
	 * @param boolean $all whether to return all data
	 * @return mixed the solr document objects populated with the query result
	 */
	protected function solrQuery($criteria,$all=false)
	{
		if(!$all)
			$criteria->setLimit(1);
        if ($criteria->query == "")
            $criteria->query = "*:*";
		$response = $this->getSolrConnection()->search($criteria,$this);
		$results = $response->getResults()->toArray();
		if (!count($results) && $criteria->getParam("group")) {
			// this is the result of a group by query
			$groups = $response->getGroups()->toArray();
			$group = array_shift($groups);
			if ($group)
				$results = $group->toArray();
		}
		if ($all) {
			return $results;
		}
		else {
			return array_shift($results);
		}

	}

    /**
     * Creates a solr document with the given attributes.
     * This method is internally used by the find methods.
     * @param array $attributes attribute values (column name=>column value)
     * @param boolean $callAfterFind whether to call {@link afterFind} after the record is populated.
     * @return ASolrDocument the newly created solr document. The class of the object is the same as the model class.
     * Null is returned if the input data is false.
     */
    public function populateRecord($attributes, $callAfterFind = true)
    {
        return static::load($attributes['id']);
    }

}