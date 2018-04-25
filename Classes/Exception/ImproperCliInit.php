<?php
namespace Innologi\TYPO3ExtUpdate\Exception;

/**
 * Improper CLI Initialization Exception
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
class ImproperCliInit extends Exception
{

    /**
     *
     * @var string
     */
    protected $message = 'Attempted to start updater from CLI, but was improperly initialized.';
}
