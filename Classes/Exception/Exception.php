<?php
namespace Innologi\TYPO3ExtUpdate\Exception;

/**
 * Exception Class
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
class Exception extends \Exception
{

    /**
     * Class constructor
     *
     * @param integer $code
     * @param array $messageArguments
     * @param string $message
     * @return void
     */
    public function __construct(?int $code = null, ?array $messageArguments = null, ?string $message = null)
    {
        $this->code = $code;
        if ($message !== null) {
            $this->message = $message;
        }
        if ($messageArguments !== null) {
            $this->message = vsprintf($this->message, $messageArguments);
        }
    }

    /**
     * Return error message formatted, prepended with code
     *
     * @return string
     */
    public function getFormattedErrorMessage(): string
    {
        return '[' . $this->code . '] ' . $this->message;
    }
}
