<?php
namespace Innologi\TYPO3ExtUpdate\Service\Exception;

/**
 * SQL Error Exception
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
class SqlError extends DatabaseException
{

    /**
     *
     * @var string
     */
    protected $message = 'The following database query produced an unknown error: <pre>%1$s</pre>';
}
