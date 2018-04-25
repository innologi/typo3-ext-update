<?php
namespace Innologi\TYPO3ExtUpdate\Service\Exception;

/**
 * Not in Document Root Exception
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
class NotInDocumentRoot extends FileException
{

    /**
     *
     * @var string
     */
    protected $message = 'The file \'%1$s\' lives outside of document root \'%2$s\'.';
}
