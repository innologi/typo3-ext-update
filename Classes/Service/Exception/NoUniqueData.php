<?php
namespace Innologi\TYPO3ExtUpdate\Service\Exception;

/**
 * No Unique Data Exception
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
class NoUniqueData extends NoData
{

    /**
     *
     * @var string
     */
    protected $message = 'No \'%1$s\' records to create.';
}
