<?php
/**
 * Represents a list of aggregate objects.
 * Takes an aggregate class name and a list of aggregate ids that
 * will be lazy loaded when required.
 */
class AAggregateList extends CComponent implements Iterator, ArrayAccess, Countable
{
    /**
     * @var array the aggregate ids to load
     */
    public $ids = array();

    /**
     * @var string the class name of the aggregate to load
     */
    public $aggregateClass;

    /**
     * @var integer the current index
     */
    public $index = 0;


    /**
     * Initializes the aggregate data reader
     * @param string $aggregateClass the aggregate class name
     * @param array $ids the aggregate ids to load
     */
    public function __construct($aggregateClass, $ids = array())
    {
        foreach($ids as $i => $value)
            if ($value instanceof AAggregateModel)
                $ids[$i] = $value->id;

        $this->ids = $ids;
        $this->aggregateClass = $aggregateClass;
    }


    /**
     * Return the current element
     * @link http://php.net/manual/en/iterator.current.php
     * @return mixed Can return any type.
     */
    public function current()
    {
        $id = $this->ids[$this->index];
        $class = $this->aggregateClass;
        if ($id)
            return $class::load($id);
    }

    /**
     * Move forward to next element
     * @link http://php.net/manual/en/iterator.next.php
     * @return void Any returned value is ignored.
     */
    public function next()
    {
        $this->index++;
    }

    /**
     * Return the key of the current element
     * @link http://php.net/manual/en/iterator.key.php
     * @return scalar scalar on success, or null on failure.
     */
    public function key()
    {
        return $this->index;
    }

    /**
     * Checks if current position is valid
     * @link http://php.net/manual/en/iterator.valid.php
     * @return boolean The return value will be casted to boolean and then evaluated.
     * Returns true on success or false on failure.
     */
    public function valid()
    {
        return $this->index > 0 && $this->index < count($this->ids);
    }

    /**
     * Rewind the Iterator to the first element
     * @link http://php.net/manual/en/iterator.rewind.php
     * @return void Any returned value is ignored.
     */
    public function rewind()
    {
        $this->index--;
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Whether a offset exists
     * @link http://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $offset <p>
     * An offset to check for.
     * </p>
     * @return boolean true on success or false on failure.
     * </p>
     * <p>
     * The return value will be casted to boolean if non-boolean was returned.
     */
    public function offsetExists($offset)
    {
        return $this->index > 0 && $this->index < count($this->ids);
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Offset to retrieve
     * @link http://php.net/manual/en/arrayaccess.offsetget.php
     * @param mixed $offset <p>
     * The offset to retrieve.
     * </p>
     * @return mixed Can return all value types.
     */
    public function offsetGet($offset)
    {
        $id = $this->ids[$offset];
        $class = $this->aggregateClass;
        if ($id)
            return $class::load($id);
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Offset to set
     * @link http://php.net/manual/en/arrayaccess.offsetset.php
     * @param mixed $offset <p>
     * The offset to assign the value to.
     * </p>
     * @param mixed $value <p>
     * The value to set.
     * </p>
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        if ($value instanceof AAggregateModel)
            $value = $value->id;
        $this->ids[$offset] = $value;
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Offset to unset
     * @link http://php.net/manual/en/arrayaccess.offsetunset.php
     * @param mixed $offset <p>
     * The offset to unset.
     * </p>
     * @return void
     */
    public function offsetUnset($offset)
    {
        unset($this->ids[$offset]);
    }

    /**
     * (PHP 5 &gt;= 5.1.0)<br/>
     * Count elements of an object
     * @link http://php.net/manual/en/countable.count.php
     * @return int The custom count as an integer.
     * </p>
     * <p>
     * The return value is cast to an integer.
     */
    public function count()
    {
        return count($this->ids);
    }

    /**
     * Return the first item in the list
     * @return AAggregateModel|boolean the first item, or false if none exists
     */
    public function first()
    {
        $class = $this->aggregateClass;
        if (count($this->ids))
            return $class::load($this->ids[0]);
        return false;
    }

    /**
     * Return the last item in the list
     * @return AAggregateModel|boolean the last item, or false if none exists
     */
    public function last()
    {
        $class = $this->aggregateClass;
        if ($count = count($this->ids))
            return $class::load($this->ids[$count - 1]);
        return false;
    }
    /**
     * Returns an array representation of the list
     * @return AAggregate[]
     */
    public function toArray()
    {
        $items = array();
        foreach($this as $aggregate)
            $items[] = $aggregate;
        return $items;
    }
}