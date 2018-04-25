<?php
namespace Innologi\TYPO3ExtUpdate\Exception;

/**
 * No Extkey Set Exception
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
class NoExtkeySet extends Exception
{

    /**
     *
     * @var string
     */
    protected $message = 'The extension updater class has no extension key set. You need to override \'$extensionKey\' in your ext_update class.';
}
