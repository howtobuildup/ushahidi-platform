<?php

/**
 * Ushahidi Reader Factory
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi\Application
 * @copyright  2014 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

namespace Ushahidi\Core\Tool\FileReader;

use Ushahidi\Core\Tool\Reader;
use Ushahidi\Contracts\ReaderFactory;

class CSVReaderFactory implements ReaderFactory
{
    public function createReader($file)
    {
        $reader = $file instanceof \SplFileObject
            ? Reader::createFromFileObject($file)
            : Reader::createFromPath($file);

        $delimiterCounts = $reader->fetchDelimitersOccurrence([',', ';', "\t"], 3);
        $delimiter = array_search(max($delimiterCounts), $delimiterCounts, true);

        if ($delimiter !== false && $delimiterCounts[$delimiter] > 0) {
            $reader->setDelimiter($delimiter);
        }

        return $reader;
    }
}
