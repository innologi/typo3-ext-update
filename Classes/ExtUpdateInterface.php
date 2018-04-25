<?php
namespace Innologi\TYPO3ExtUpdate;

/**
 * Ext Update Interface
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
interface ExtUpdateInterface
{

    /**
     * Provides the methods to be executed during update.
     *
     * @return boolean TRUE on complete, FALSE on incomplete
     */
    public function processUpdates(): bool;
}
