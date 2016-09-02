<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\DBAL\Driver\OCI8;

use PDO;
use IteratorAggregate;
use Doctrine\DBAL\Driver\Statement;

/**
 * The OCI8 implementation of the Statement interface.
 *
 * @since 2.0
 * @author Roman Borschel <roman@code-factory.org>
 */
class OCI8Statement implements \IteratorAggregate, Statement
{
    /**
     * @var resource
     */
    protected $_dbh;

    /**
     * @var resource
     */
    protected $_sth;

    /**
     * @var \Doctrine\DBAL\Driver\OCI8\OCI8Connection
     */
    protected $_conn;

    /**
     * @var string
     */
    protected static $_PARAM = ':param';

    /**
     * @var array
     */
    protected static $fetchModeMap = array(
        PDO::FETCH_BOTH => OCI_BOTH,
        PDO::FETCH_ASSOC => OCI_ASSOC,
        PDO::FETCH_NUM => OCI_NUM,
        PDO::FETCH_COLUMN => OCI_NUM,
    );

    /**
     * @var integer
     */
    protected $_defaultFetchMode = PDO::FETCH_BOTH;

    /**
     * @var array
     */
    protected $_paramMap = array();

    /**
     * Holds references to bound parameter values.
     *
     * This is a new requirement for PHP7's oci8 extension that prevents bound values from being garbage collected.
     *
     * @var array
     */
    private $boundValues = array();

    /**
     * Indicates whether the statement is in the state when fetching results is possible
     *
     * @var bool
     */
    private $result = false;

    /**
     * Creates a new OCI8Statement that uses the given connection handle and SQL statement.
     *
     * @param resource                                  $dbh       The connection handle.
     * @param string                                    $statement The SQL statement.
     * @param \Doctrine\DBAL\Driver\OCI8\OCI8Connection $conn
     */
    public function __construct($dbh, $statement, OCI8Connection $conn)
    {
        list($statement, $paramMap) = self::convertPositionalToNamedPlaceholders($statement);
        $this->_sth = oci_parse($dbh, $statement);
        $this->_dbh = $dbh;
        $this->_paramMap = $paramMap;
        $this->_conn = $conn;
    }

    /**
     * Converts positional (?) into named placeholders (:param<num>).
     *
     * Oracle does not support positional parameters, hence this method converts all
     * positional parameters into artificially named parameters. Note that this conversion
     * is not perfect. All question marks (?) in the original statement are treated as
     * placeholders and converted to a named parameter.
     *
     * The algorithm uses a state machine with two possible states: InLiteral and NotInLiteral.
     * Question marks inside literal strings are therefore handled correctly by this method.
     * This comes at a cost, the whole sql statement has to be looped over.
     *
     * @todo extract into utility class in Doctrine\DBAL\Util namespace
     * @todo review and test for lost spaces. we experienced missing spaces with oci8 in some sql statements.
     *
     * @param string $statement The SQL statement to convert.
     *
     * @return string
     * @throws \Doctrine\DBAL\Driver\OCI8\OCI8Exception
     */
    static public function convertPositionalToNamedPlaceholders($statement)
    {
        $paramMap = array();
        $count = 1;
        $start = $offset = 0;
        $fragments = array();
        $quote = false;
        $capture = function ($regex, $callback) use ($statement, $start, &$offset) {
            if (preg_match($regex, $statement, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                $offset = $matches[0][1];
                $callback($matches[0][0]);
                return true;
            }
            return false;
        };

        do {
            if (!$quote) {
                $result = $capture('/[?\'"]/', function ($token) use (
                    $statement,
                    &$fragments,
                    &$start,
                    &$offset,
                    &$quote,
                    &$count,
                    &$paramMap
                ) {
                    if ($token == '?') {
                        $param = ':param' . $count;
                        $fragments[] = substr($statement, $start, $offset - $start);
                        $fragments[] = $param;
                        $paramMap[$count] = $param;
                        $start = ++$offset;
                        ++$count;
                    } else {
                        $quote = $token;
                        ++$offset;
                    }
                });
            } else {
                $result = $capture('/(?<!\\\\)' . $quote . '/', function () use (&$offset, &$quote) {
                    $quote = false;
                    ++$offset;
                });
            }
        } while ($result);

        if ($quote) {
            throw new OCI8Exception(sprintf(
                'The statement contains non-terminated string literal starting at offset %d',
                $offset - 1
            ));
        }

        if ($start < strlen($statement)) {
            $fragments[] = substr($statement, $start);
        }

        $statement = implode('', $fragments);

        return array($statement, $paramMap);
    }

    /**
     * {@inheritdoc}
     */
    public function bindValue($param, $value, $type = null)
    {
        return $this->bindParam($param, $value, $type, null);
    }

    /**
     * {@inheritdoc}
     */
    public function bindParam($column, &$variable, $type = null, $length = null)
    {
        $column = isset($this->_paramMap[$column]) ? $this->_paramMap[$column] : $column;

        if ($type == \PDO::PARAM_LOB) {
            $lob = oci_new_descriptor($this->_dbh, OCI_D_LOB);
            $lob->writeTemporary($variable, OCI_TEMP_BLOB);

            $this->boundValues[$column] =& $lob;

            return oci_bind_by_name($this->_sth, $column, $lob, -1, OCI_B_BLOB);
        } elseif ($length !== null) {
            $this->boundValues[$column] =& $variable;

            return oci_bind_by_name($this->_sth, $column, $variable, $length);
        }

        $this->boundValues[$column] =& $variable;

        return oci_bind_by_name($this->_sth, $column, $variable);
    }

    /**
     * {@inheritdoc}
     */
    public function closeCursor()
    {
        // not having the result means there's nothing to close
        if (!$this->result) {
            return true;
        }

        oci_cancel($this->_sth);

        $this->result = false;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function columnCount()
    {
        return oci_num_fields($this->_sth);
    }

    /**
     * {@inheritdoc}
     */
    public function errorCode()
    {
        $error = oci_error($this->_sth);
        if ($error !== false) {
            $error = $error['code'];
        }

        return $error;
    }

    /**
     * {@inheritdoc}
     */
    public function errorInfo()
    {
        return oci_error($this->_sth);
    }

    /**
     * {@inheritdoc}
     */
    public function execute($params = null)
    {
        if ($params) {
            $hasZeroIndex = array_key_exists(0, $params);
            foreach ($params as $key => $val) {
                if ($hasZeroIndex && is_numeric($key)) {
                    $this->bindValue($key + 1, $val);
                } else {
                    $this->bindValue($key, $val);
                }
            }
        }

        $ret = @oci_execute($this->_sth, $this->_conn->getExecuteMode());
        if ( ! $ret) {
            throw OCI8Exception::fromErrorInfo($this->errorInfo());
        }

        $this->result = true;

        return $ret;
    }

    /**
     * {@inheritdoc}
     */
    public function setFetchMode($fetchMode, $arg2 = null, $arg3 = null)
    {
        $this->_defaultFetchMode = $fetchMode;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator()
    {
        $data = $this->fetchAll();

        return new \ArrayIterator($data);
    }

    /**
     * {@inheritdoc}
     */
    public function fetch($fetchMode = null)
    {
        // do not try fetching from the statement if it's not expected to contain result
        // in order to prevent exceptional situation
        if (!$this->result) {
            return false;
        }

        $fetchMode = $fetchMode ?: $this->_defaultFetchMode;

        if (PDO::FETCH_OBJ == $fetchMode) {
            return oci_fetch_object($this->_sth);
        }

        if (! isset(self::$fetchModeMap[$fetchMode])) {
            throw new \InvalidArgumentException("Invalid fetch style: " . $fetchMode);
        }

        return oci_fetch_array(
            $this->_sth,
            self::$fetchModeMap[$fetchMode] | OCI_RETURN_NULLS | OCI_RETURN_LOBS
        );
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAll($fetchMode = null)
    {
        $fetchMode = $fetchMode ?: $this->_defaultFetchMode;

        $result = array();

        if (PDO::FETCH_OBJ == $fetchMode) {
            while ($row = $this->fetch($fetchMode)) {
                $result[] = $row;
            }

            return $result;
        }

        if ( ! isset(self::$fetchModeMap[$fetchMode])) {
            throw new \InvalidArgumentException("Invalid fetch style: " . $fetchMode);
        }

        if (self::$fetchModeMap[$fetchMode] === OCI_BOTH) {
            while ($row = $this->fetch($fetchMode)) {
                $result[] = $row;
            }
        } else {
            $fetchStructure = OCI_FETCHSTATEMENT_BY_ROW;
            if ($fetchMode == PDO::FETCH_COLUMN) {
                $fetchStructure = OCI_FETCHSTATEMENT_BY_COLUMN;
            }

            // do not try fetching from the statement if it's not expected to contain result
            // in order to prevent exceptional situation
            if (!$this->result) {
                return array();
            }

            oci_fetch_all($this->_sth, $result, 0, -1,
                self::$fetchModeMap[$fetchMode] | OCI_RETURN_NULLS | $fetchStructure | OCI_RETURN_LOBS);

            if ($fetchMode == PDO::FETCH_COLUMN) {
                $result = $result[0];
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchColumn($columnIndex = 0)
    {
        // do not try fetching from the statement if it's not expected to contain result
        // in order to prevent exceptional situation
        if (!$this->result) {
            return false;
        }

        $row = oci_fetch_array($this->_sth, OCI_NUM | OCI_RETURN_NULLS | OCI_RETURN_LOBS);

        if (false === $row) {
            return false;
        }

        return isset($row[$columnIndex]) ? $row[$columnIndex] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function rowCount()
    {
        return oci_num_rows($this->_sth);
    }
}
