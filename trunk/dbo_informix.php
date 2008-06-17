<?php
/**
 *********************************************************
 *  Driver que implementa la API DBOSource para Informix 7.3
 *  
 *  NOTE: No soporta Blobs en insert y update, pero si en select con ifx.textasvarchar = 1
 *  
 *
 *  Creacion de este archivo: 04/09/2006
 *  Nombre original del archivo: dbo_informix.php
 *
 *  Universidad Nacional de San Luis
 *  Direccion General de Informatica
 *  Centro de Computos
 *  Ejercito de los Andes 950 - 2do Piso
 *
 *  Autor para CakePHP 1.1.7 : Diego F. Quiroga - diegoq@unsl.edu.ar
 *  Autor para CakePHP 1.2 : Marcelo Morales - mmorales@unsl.edu.ar - Adaptación completa a CakePHP 1.2
 *  Bugfix: Las relaciones HABTM no eran resueltas correctamente  y tipos de datos
 *  $Id$
 ********************************************************
 */

// loading extension based on OS
if (!extension_loaded('informix'))
{
    if (strtoupper(substr(PHP_OS, 0, 3) == 'WIN'))
    {
        dl('php_ifx.dll');
    }
    else
    {
        dl('informix.so');
    }

    // If on, select statements return the contents of a text blob instead of its id.
    ini_set('ifx.textasvarchar', '1');

    // If on, select statements return the contents of a byte blob instead of its id.
    ini_set('ifx.byteasvarchar', '1');

    // Trailing blanks are stripped from fixed-length char columns.  May help the
    // life of Informix SE users.
    ini_set('ifx.charasvarchar', '1');

}

/**
 * Include DBO.
 */
uses('model' . DS . 'datasources' . DS . 'dbo_source');
/**
 * DBO Informix driver for CakePHP.
 *
 * Implements the DBO API for Informix Database Server
 *
 */
class DboInformix extends DboSource
{
    /**
     * Enter description here...
     *
     * @var unknown_type
     */
    var $description = "Informix DBO Driver";

    /**
     * Base configuration settings for Informix driver
     *
     * @var array
     */
    var $_baseConfig = array('persistent' => false, 'host' => 'localhost', 'login' =>
        'root', 'password' => '', 'database' => 'cake', 'connect' => 'ifx_connect');

    var $columns = array('primary_key' => array('name' => 'serial NOT NULL'),
        //'serial' => array('name' => 'serial'), // no cumple el estandar
        'string' => array('name' => 'varchar', 'limit' => '255'), 'text' => array('name' =>
        'text'), 'integer' => array('name' => 'integer', 'formatter' => 'intval'),
        'float' => array('name' => 'float', 'formatter' => 'floatval'), 'datetime' =>
        array('name' => 'datetime year to second', 'format' => 'Y-m-d H:i:s',
        'formatter' => 'date'), 'timestamp' => array('name' =>
        'datetime year to fraction', 'format' => 'Y-m-d H:i:s', 'formatter' => 'date'),
        'time' => array('name' => 'datetime hour to second ', 'format' => 'H:i:s',
        'formatter' => 'date'), 'date' => array('name' => 'date', 'format' => 'Y-m-d',
        'formatter' => 'date'), 'binary' => array('name' => 'blob'), 'boolean' => array
        ('name' => 'integer', 'limit' => '1'), 'number' => array('name' => 'numeric'), );


    /**
     * Indica si el fetch tiene que simular un limit.
     */
    var $limit = null;
    var $offset = 0;
    var $num_record = 0;
    var $model = null;

    /**
     * Creates a map between field aliases and numeric indexes.  Workaround for the
     * Informix driver's 18-character column name limitation.
     *
     * @var array
     */
    var $__fieldMappings = array();


    /**
     * Connects to the database using options in the given configuration array.
     *
     * @return boolean True if the database could be connected, else false
     */
    function connect()
    {
        $config = $this->config;
        $connect = $config['connect'];
        $this->connected = false;


        $host = $config['database'] . '@' . $config['host'];

        if ($config['persistent'])
        {
            $this->connection = ifx_pconnect($host, $config['login'], $config['password']);
        }
        else
        {
            $this->connection = $connect($host, $config['login'], $config['password']);
        }

        $this->connected = ($this->connection !== false);
        return $this->connected;
    }
    /**
     * Disconnects from database.
     *
     * @return boolean True if the database could be disconnected, else false
     */
    function disconnect()
    {
        if ($this->_result)
            @ifx_free_result($this->_result);
        $this->connected = !@ifx_close($this->connection);
        return !$this->connected;
    }
    /**
     * Executes given SQL statement.
     *
     * @param string $sql SQL statement
     * @return resource Result resource identifier
     * @access protected
     */
    function _execute($sql)
    {
        if (!preg_match('/[\b]*select(?:.|\n)*/i', $sql)) {
            $this->limit = null;
        }
        if ($this->limit != null)
        {
            return ifx_query($sql, $this->connection, IFX_SCROLL);
        }
        else
        {
            return ifx_query($sql, $this->connection);
        }      
    }

    /**
     * Returns a row from given resultset as an array .
     *
     * Returns a row from given resultset as an array. Check if limit is set to stop the fetching when limit is reached.
     *
     * @param bool $assoc Associative array only, or both?
     * @return array The fetched row as an array
     */

    function fetchRow($sql = false)
    {
        if (!empty($sql) && is_string($sql) && strlen($sql) > 5)
        {
            if (!$this->execute($sql))
            {
                return null;
            }
        }

        if (is_resource($this->_result) || is_object($this->_result))
        {
            $this->resultSet($this->_result);
            $resultRow = $this->fetchResult();
            return $resultRow;
        }
        else
        {
            return null;
        }

    }


    function resultSet(&$results)
    {

        $this->results = &$results;
        $this->map = array();

        $columns = array_keys(ifx_fieldtypes($results));

        $num_fields = count($columns);
        $index = 0;
        $j = 0;

        while ($j < $num_fields)
        {
            $column = strtolower($columns[$j]);
            if (strpos($column, '__'))
            {
                if (isset($this->__fieldMappings[$column]) && strpos($this->__fieldMappings[$column],
                    '.'))
                {
                    $map = explode('.', $this->__fieldMappings[$column]);
                } elseif (isset($this->__fieldMappings[$column]))
                {
                    $map = array(0, $this->__fieldMappings[$column]);
                }
                else
                {
                    $map = array(0, $column);
                }
                $this->map[$index++] = $map;
            }
            else
            {
                $this->map[$index++] = array(0, $column);
            }
            $j++;
        }
    }

    function fetchResult()
    {
        if ($this->_result)
        {
            if (isset($this->limit) && ($this->limit !== null))
            {
                if ($this->num_record <= ($this->offset + $this->limit))
                {
                    $record = $this->num_record;
                    $this->num_record++;
                }
                else
                {
                    $this->limit = null;
                    $this->offset = null;
                    return null;
                }
            }
            else
            {
                $record = "NEXT";
            }
        }

        if ($row = ifx_fetch_row($this->_result, $record))
        {

            $resultRow = array();
            $i = 0;

            foreach ($row as $index => $field)
            {
                if (array_key_exists($index, $this->__fieldMappings))
                {
                    list($table, $column) = $this->map[$i];
                    $resultRow[$table][$column] = $row[$index];
                }
                else
                {
                    $resultRow[0][str_replace('"', '', $index)] = $row[$index];
                }

                $i++;
            }

            return $resultRow;

        }
        else
        {
            return false;
        }
    }


    /**
     * Returns an array of sources (tables) in the database.
     *
     * Returns an array of tables in the database. Do not return Informix system tables.
     *
     * @return array Array of tablenames in the database
     */
    function listSources()
    {
        $cache = parent::listSources();
        if ($cache != null)
        {
            return $cache;
        }
        $sql = 'SELECT Systable.tabname, Systable.tabid FROM systables as Systable WHERE Systable.tabid >= 100';
        $result = @ifx_query($sql, $this->connection);

        if (!$result)
        {
            return array();
        }
        else
        {

            $tables = array();
            while ($line = ifx_fetch_row($result))
            {
                $tables[] = trim($line['tabname']);
            }

            parent::listSources($tables);
            return $tables;
        }
    }
    /**
     * Returns an array of the fields in given table name.
     *
     * @param string $tableName Name of database table to inspect
     * @return array Fields in table. Keys are name and type
     */
    function describe(&$model, $clear = false)
    {
        $this->model = $model;
        $cache = parent::describe($model, $clear);
        if ($cache != null)
        {
            return $cache;
        }

        $fields = array();

        $tableName = $this->fullTableName($model);
        //$tableName = $model->table;
        $sql = "SELECT FIRST 1 * FROM {$tableName} WHERE 1=1";
        $id = @ifx_query($sql, $this->connection);
        $flds = @ifx_fieldproperties($id);
        /* ifx_fieldproperties help
        Returns an associative array with fieldnames as key and the SQL fieldproperties
        as data for a query with result_id. Returns FALSE on error.
        Returns the Informix SQL fieldproperties of every field in the query as
        associative array. Properties are encoded as:
        "SQLTYPE;length;precision;scale;ISNULLABLE"
        where SQLTYPE = the Informix type like "SQLVCHAR" etc. and ISNULLABLE = "Y" or "N".
        */
        $fields = array();
        foreach ($flds as $fieldname => $properties_string)
        {
            $props = explode(';', $properties_string);

            $fields[$fieldname] = array('type' => $this->column($props[0]), 'length' => $props[1],
                'precision' => $props[2], 'scale' => $props[3], 'null' => $props[4] == 'N' ? false : true, );

        }
        $this->__cacheDescription($this->fullTableName($model, false), $fields);
        return $fields;
    }


    /**
     * Returns a quoted and escaped string of $data for use in an SQL statement.
     *
     * @param string $data String to be prepared for use in an SQL statement
     * @param string $column The column into which this data will be inserted
     * @param boolean $safe Whether or not numeric data should be handled automagically if no column data is provided
     * @return string Quoted and escaped data
     */
    function value($data, $column = null, $safe = false)
    {
        $parent = parent::value($data, $column, $safe);

        if ($parent != null)
        {
            return $parent;
        }

        if ($data === null)
        {
            return 'NULL';
        }

        if ($data == '')
        {
            return "''";
        }

        switch ($column)
        {

            case 'date':
                return "'" . $data . "'";
                break;

            case 'datetime':
                return "DATETIME (" . $data . ") YEAR TO SECOND";
                break;

            case 'timestamp':
                return "DATETIME (" . $data . ") YEAR TO FRACTION";
                break;

            case 'time':
                return "DATETIME (" . $data . ") HOUR TO SECOND";
                break;

            case 'boolean':
                return $this->boolean((bool)$data);
                break;

            case 'text':
            case 'string':
                return "'" . $data . "'";
                break;

            case 'number':
            case 'float':
                if (is_string($data))
                {
                    $res = "'" . trim($data) . "'";
                }
                else
                {
                    $res = $data;
                }
                return $res;
                break;
            case 'integer':
                if (is_string($data))
                {
                    $res = "'" . trim($data) . "'";
                }
                else
                {
                    $res = $data;
                }
                return $res;
                break;

            case 'serial':
                if (is_string($data))
                {
                    $res = "'" . trim($data) . "'";
                }
                else
                {
                    $res = $data;
                }
                return $res;
                break;

            default:
                if (is_string($data))
                {
                    if (ini_get('magic_quotes_gpc') != 1)
                    {
                        $data = addslashes($data);
                    }
                    return "'" . $data . "'";
                }
                else
                {
                    return $data;
                }
                break;
        }
    }


    function column($real)
    {
        switch ($real)
        {

            case 'SQLLVARCHAR':
            case 'SQLNCHAR':
            case 'SQLNVCHAR':
            case 'SQLVCHAR':
            case 'SQLCHAR':
            case 'SQLNCHAR':
                return 'string';

            case 'SQLSERIAL':
            case 'SQLSERIAL8':
                return 'serial';

            case 'SQLSMINT':
            case 'SQLINT':
            case 'SQLINT8':
                return 'integer';


            case 'SQLFLOAT':
            case 'SQLSMFLOAT':
            case 'SQLDECIMAL':
                return 'float';
                /* ??????? */
            case 'SQLDATE':
                return 'date';
            case 'SQLMONEY':
                return 'number';
            case 'SQLDTIME':
                return 'datetime';
            case 'SQLTEXT':
                return 'text';
            case 'SQLBYTES':
                return 'binary';

            case 'SQLINTERVAL':
                return 'timestamp';

            case 'SQLBOOL':
                return 'boolean';
        }


    }

    /**
     * Begin a transaction
     *
     * @param unknown_type $model
     * @return boolean True on success, false on fail
     * (i.e. if the database/model does not support transactions).
     */
    function begin(&$model)
    {
        if (parent::begin($model))
        {
            if ($this->execute('BEGIN'))
            {
                $this->__transactionStarted = true;
                return true;
            }
        }
        return false;
    }
    /**
     * Commit a transaction
     *
     * @param unknown_type $model
     * @return boolean True on success, false on fail
     * (i.e. if the database/model does not support transactions,
     * or a transaction has not started).
     */
    function commit(&$model)
    {
        if (parent::commit($model))
        {
            $this->__transactionStarted = false;
            return $this->execute('COMMIT');
        }
        return false;
    }
    /**
     * Rollback a transaction
     *
     * @param unknown_type $model
     * @return boolean True on success, false on fail
     * (i.e. if the database/model does not support transactions,
     * or a transaction has not started).
     */
    function rollback(&$model)
    {
        if (parent::rollback($model))
        {
            return $this->execute('ROLLBACK');
        }
        return false;
    }
    /**
     * Returns a formatted error message from previous database operation.
     *
     * @return string Error message with error number
     */
    function lastError()
    {
        if ($this->_errorNumber() != 0)
        {
            return ifx_errormsg($this->_errorNumber());
        }
        return false;
    }

    function _errorNumber()
    {

        preg_match("/.*SQLCODE=([^\]]*)/", ifx_error(), $parse);
        if (is_array($parse) && isset($parse[1]))
        {
            return (int)$parse[1];
        }
        else
        {
            return 0;
        }

    }

    /**
     * Returns number of affected rows in previous database operation. If no previous operation exists,
     * this returns false. For select statements this is not a very reliable value.
     *
     * @return int Number of affected rows
     */
    function lastAffected()
    {
        if ($this->_result)
        {
            $NR = @ifx_affected_rows($this->_result);
            return $NR;
        }
        return false;
    }

    /**
     * Returns number of rows in previous resultset. If no previous resultset exists,
     * this returns false.
     *
     * @return int Number of rows in resultset
     */
    function lastNumRows()
    {
        if ($this->_result && is_resource($this->_result))
        {

            $NR = @ifx_num_rows($this->_result);
            return $NR;
        }
        return false;
    }

    /**
     * Returns the ID generated from the previous INSERT operation.
     *
     * @param unknown_type $source
     * @return in
     */
    function lastInsertId($source = null)
    {
        $sqlca = ifx_getsqlca($this->_result);
        return $sqlca["sqlerrd1"];
    }


    /**
     * Returns a limit statement in the correct format for the particular database.
     *
     * Informix has not a limit statement, so limits here have been simulated setting flags as needed
     * to fetch only selected rows.
     *
     * @param integer $limit Limit of results returned
     * @param integer $offset Offset from which to start results
     * @return string SQL limit/offset statement
     *
     */

    function limit($limit, $offset = null)
    {
        if ($limit)
        {
            if ($offset == null)
            {
                $offset = 0;
            }
            $this->offset = $offset;
            $this->limit = $limit;
            $this->num_record = $offset + 1;
            return 'FIRST ' . ($limit + $offset);
        }

        return null;
    }

    function buildStatement($query, $model)
    {


        $query = array_merge(array('offset' => null, 'joins' => array()), $query);
        //echo "<b>query despues de merge</b>";pr($query);
        if (!empty($query['joins']))
        {
            for ($i = 0; $i < count($query['joins']); $i++)
            {
                if (is_array($query['joins'][$i]))
                {
                    if (is_array($query['joins'][$i]['conditions']))
                    {
                        foreach ($query['joins'][$i]['conditions'] as $cond)
                        {
                            $query['conditions'][] = $cond;
                        }
                    }
                    else
                    {
                        $query['conditions'][] = $query['joins'][$i]['conditions'];
                    }
                    $query['joins'][$i] = $this->buildJoinStatement($query['joins'][$i]);
                }
            }
        }
        $res = $this->renderStatement('select', array('conditions' => $this->conditions
            ($query['conditions']), 'fields' => join(', ', $query['fields']), 'table' => $query['table'],
            'alias' => $this->alias . $this->name($query['alias']), 'order' => $this->order
            ($query['order']), 'limit' => $this->limit($query['limit'], $query['offset']),
            'joins' => join(' ', $query['joins'])));


        return $res;
    }


    function renderJoinStatement($data)
    {
        extract($data);
        $res = trim(", OUTER {$table} {$alias}");
        return $res;
    }

    /**
     * Generates the fields list of an SQL query.
     *
     * @param Model $model
     * @param string $alias Alias tablename
     * @param mixed $fields
     * @return array
     */
    function fields(&$model, $alias = null, $fields = array(), $quote = true)
    {
        if (empty($alias))
        {
            $alias = $model->alias;
        }

        $fields = parent::fields($model, $alias, $fields, false);
        $count = count($fields);

        //pr($fields);

        $orig_fields = array_flip($this->__fieldMappings);

        if ($count >= 1 && $fields[0] != '*' && strpos($fields[0], 'COUNT(*)') === false)
        {
            for ($i = 0; $i < $count; $i++)
            {
                $hasDot = strrpos($fields[$i], '.');
                $hasAs = !(strpos($fields[$i], ' AS ') === false);

                $orig_fields = array_flip($this->__fieldMappings);

                $fieldAlias = count($this->__fieldMappings);

                if ($hasAs)
                { // si tiene As no hago nada
                } elseif ($hasDot)
                { // no tiene As pero tiene punto
                    $qualified_name = $fields[$i];
                    if (key_exists($qualified_name, $orig_fields))
                    {
                        $qualified_name = $fields[$i];
                        $alias_field = $orig_fields[$qualified_name];
                        $fields[$i] = $this->name($qualified_name) . ' AS ' . $this->name($alias_field);
                    }
                    else
                    {
                        list($tablealias, $fieldname) = $build = explode('.', $qualified_name);
                        $alias_field = strtolower($tablealias . '__' . $fieldAlias);
                        $this->__fieldMappings[$alias_field] = $qualified_name;
                        $fields[$i] = $this->name($qualified_name) . ' AS ' . $this->name($alias_field);
                    }
                }
                else
                { //no tiene AS ni punto
                    $qualified_name = $alias . '.' . $fields[$i];
                    if (key_exists($qualified_name, $orig_fields))
                    {
                        $alias_field = $orig_fields[$qualified_name];
                        $fields[$i] = $this->name($qualified_name) . ' AS ' . $this->name($alias_field);
                    }
                    else
                    {
                        $alias_field = strtolower($alias . '__' . $fieldAlias);
                        $this->__fieldMappings[$alias_field] = $qualified_name;
                        $fields[$i] = $this->name($qualified_name) . ' AS ' . $this->name($alias_field);
                    }
                }
            }
        }


        return $fields;
    }

    /**
     * Reverses the sort direction of ORDER statements to get paging offsets to work correctly
     *
     * @param string $order
     * @return string
     * @access private
     */
    function __switchSort($order)
    {
        return $order;
        /*		$order = preg_replace('/\s+ASC/i', '__tmp_asc__', $order);
        $order = preg_replace('/\s+DESC/i', ' ASC', $order);
        return preg_replace('/__tmp_asc__/', ' DESC', $order);
        */
    }
    /**
     * Translates field names used for filtering and sorting to shortened names using the field map
     *
     * @param string $sql A snippet of SQL representing an ORDER or WHERE statement
     * @return string The value of $sql with field names replaced
     * @access private
     */
    function __mapFields($sql)
    {
        if (empty($sql) || empty($this->__fieldMappings))
        {
            return $sql;
        }
        foreach ($this->__fieldMappings as $key => $val)
        {
            $sql = preg_replace('/' . preg_quote($val) . '/', $this->name($key), $sql);
            $sql = preg_replace('/' . preg_quote($this->name($val)) . '/', $this->name($key),
                $sql);
        }
        return $sql;
    }
    /**
     * Returns an array of all result rows for a given SQL query.
     * Returns false if no rows matched.
     *
     * @param string $sql SQL statement
     * @param boolean $cache Enables returning/storing cached query results
     * @return array Array of resultset rows, or false if no rows matched
     */
    function read(&$model, $queryData = array(), $recursive = null)
    {
        $results = parent::read($model, $queryData, $recursive);
        $this->__fieldMappings = array();
        $this->__fieldMapBase = null;
        return $results;
    }
    /**
     * Builds final SQL statement
     *
     * @param string $type Query type
     * @param array $data Query data
     * @return string
     */

    function renderStatement($type, $data)
    {
        extract($data);

        if (strtolower($type) == 'select')
        {
            return "SELECT {$limit} {$fields} FROM {$table} {$alias} {$joins} {$conditions} {$order}";
        }
        else
        {
            return parent::renderStatement($type, $data);
        }
    }
    /**
     * Deletes all the records in a table and resets the count of the auto-incrementing
     * primary key, where applicable.
     *
     * @param mixed $table A string or model class representing the table to be truncated
     * @return boolean	SQL TRUNCATE TABLE statement, false if not applicable.
     * @access public
     */
    function truncate($table)
    {
        return $this->execute('DELETE FROM ' . $this->fullTableName($table));
    }


    function update(&$model, $fields = array(), $values = null, $conditions = null)
    {
        $updates = array();

        if ($values == null)
        {
            $combined = $fields;
        }
        else
        {
            $combined = array_combine($fields, $values);
        }
        foreach ($combined as $field => $value)
        {
            // check if field datatype is serial
            if ($model->getColumnType($field) == 'serial')
            {
                continue;
            }
            if ($value === null)
            {

                $updates[] = $this->fullTableName($model) . '.' . $field . ' = NULL';
            }
            else
            {

                $update = $this->fullTableName($model) . '.' . $field . ' = ';
                if ($conditions == null)
                {
                    $update .= $this->value($value, $model->getColumnType($field));
                }
                else
                {
                    $update .= $value;
                }
                $updates[] = $update;
            }
        }
        $conditions = $this->_UpdateConditions($model, $conditions);
        if ($conditions === false)
        {
            return false;
        }
        $fields = join(', ', $updates);
        $table = $this->fullTableName($model);
        $conditions = $this->conditions($conditions);
        $alias = $this->name($model->alias);
        $joins = implode(' ', $this->_getJoins($model));

        if (!$this->execute($this->renderStatement('update', compact('table', 'alias',
            'joins', 'fields', 'conditions'))))
        {
            $model->onError();
            return false;
        }
        return true;
    }

    /**
     * Creates a default set of conditions from the model if $conditions is null/empty.
     *
     * @param object $model
     * @param mixed  $conditions
     * @return mixed
     */
    function _UpdateConditions(&$model, $conditions)
    {
        if (!empty($conditions))
        {
            return $conditions;
        }
        if (!$model->exists())
        {
            return false;
        }
        $fullname = $this->fullTableName($model) . '.' . $model->primaryKey;

        return array("{$fullname}" => (array )$model->getID());
    }

    /**
     * Generate a database-native column schema string
     * map key=>primary extra=>autoincrement to serial NOT NULL
     *  
     * @param array $column An array structured like the following: array('name'=>'value', 'type'=>'value'[, options]),
     *                      where options can be 'default', 'length', or 'key'.
     * @return string
     */
    function buildColumn($column)
    {
        $res = parent::buildColumn($column);

        if ($res == null)
        {
            return null;
        }
        else
        {
            $name = $type = null;
            $column = array_merge(array('null' => true), $column);
            extract($column);
            $out = $res;
            if (isset($column['key']) && $column['key'] == 'primary' && (isset($column['extra']) &&
                $column['extra'] == 'auto_increment'))
            {
                $out = $this->name($name) . ' serial NOT NULL';
            }
        }
        return $out;
    }
}

?>