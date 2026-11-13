<?php

/**
 * Exception for a failed FHIR request, carrying the HTTP status.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @link      https://github.com/openemr/oe-smart-questionnaire
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\SmartQuestionnaire;

use RuntimeException;
use Throwable;

final class FhirException extends RuntimeException
{
    /** @param int $httpStatus the server's HTTP status, or 0 when no response arrived (transport failure) */
    public function __construct(string $message, private readonly int $httpStatus = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
