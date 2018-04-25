<?php
namespace Innologi\TYPO3ExtUpdate\Service\Exception;

/**
 * File Does Not Exist Exception
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
class FileDoesNotExist extends FileException
{

    /**
     *
     * @var string
     */
    protected $message = 'The file \'%1$s\' does not exist.';
}
