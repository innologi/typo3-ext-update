<?php
namespace Innologi\TYPO3ExtUpdate\Exception;

/**
 * Extension Not Loaded Exception
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
class ExtensionNotLoaded extends Exception
{

    /**
     *
     * @var string
     */
    protected $message = 'Source extension \'%1$s\' is not loaded, cannot run updater.';
}
