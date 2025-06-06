<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Services;

use DateTimeImmutable;
use Elabftw\Elabftw\FsTools;
use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Models\Config;
use HTMLPurifier;
use HTMLPurifier_HTML5Config;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

use function checkdate;
use function filter_var;
use function htmlspecialchars_decode;
use function mb_convert_encoding;
use function mb_strlen;
use function strlen;
use function trim;

/**
 * When values need to be filtered
 */
final class Filter
{
    /**
     * ~= max size of MEDIUMTEXT in MySQL for UTF-8
     * But here it's less than that because while trying different sizes
     * I found this value to work, but not above.
     * Anyway, a few millions characters should be enough to report an experiment.
     */
    private const int MAX_BODY_SIZE = 4120000;

    public static function toBinary(string|bool|int $input): int
    {
        return $input ? 1 : 0;
    }

    /**
     * Return 0 or 1 if input is on. Used for UCP.
     */
    public static function onToBinary(?string $input): int
    {
        return $input === 'on' ? 1 : 0;
    }

    public static function firstLetter(string $input): string
    {
        $key = $input[0];
        if (ctype_alpha($key)) {
            return $key;
        }
        throw new ImproperActionException('Incorrect value: must be a letter.');
    }

    /**
     * Make sure the date is correct (YYYY-MM-DD)
     */
    public static function kdate(string $input): string
    {
        // Check if day/month/year are good
        $year = (int) substr($input, 0, 4);
        $month = (int) substr($input, 5, 2);
        $day = (int) substr($input, 8, 2);
        if (mb_strlen($input) !== 10 || !checkdate($month, $day, $year)) {
            return date('Y-m-d');
        }
        return $input;
    }

    /**
     * Return the date in a readable format
     * example: 2014-01-12 -> "Sunday, January 12, 2014"
     */
    public static function formatLocalDate(DateTimeImmutable $input): string
    {
        return $input->format('l, F j, Y');
    }

    /**
     * Returns an array (key => value) containing date and time
     * example : "2024-10-16 17:12:47" -> ["date" => "2024-10-16", "time" => "17:12:47"]
     */
    public static function separateDateAndTime(string $input): array
    {
        $date = explode(' ', $input);
        return array(
            'date' => $date[0],
            'time' => $date[1] ?? '',
        );
    }

    /**
     * Simply sanitize email
     */
    public static function sanitizeEmail(string $input): string
    {
        $output = filter_var($input, FILTER_SANITIZE_EMAIL);
        /** @psalm-suppress TypeDoesNotContainType see https://github.com/vimeo/psalm/issues/10561 */
        if ($output === false) {
            return '';
        }
        return $output;
    }

    public static function email(string $input): string
    {
        // if the sent email is different from the existing one, check it's valid (not duplicate and respects domain constraint)
        $Config = Config::getConfig();
        $EmailValidator = new EmailValidator($input, (bool) $Config->configArr['admins_import_users'], $Config->configArr['email_domain']);
        return $EmailValidator->validate();
    }

    /**
     * Sanitize title with a filter_var and remove the line breaks.
     *
     * @param string $input The title to sanitize
     * @return string Will return Untitled if there is no input.
     */
    public static function title(string $input): string
    {
        $title = trim($input);
        if ($title === '') {
            return _('Untitled');
        }
        // remove linebreak to avoid problem in javascript link list generation on editXP
        return str_replace(array("\r\n", "\n", "\r"), ' ', $title);
    }

    /**
     * Remove all non word characters. Used for files saved on the filesystem (pdf, zip, ...)
     * This code is from https://developer.wordpress.org/reference/functions/sanitize_file_name/
     *
     * @param string $input what to sanitize
     * @return string the clean string
     */
    public static function forFilesystem(string $input): string
    {
        $specialChars = array('?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', chr(0));
        $input = htmlspecialchars_decode($input, ENT_QUOTES);
        $input = str_replace("#\x{00a0}#siu", ' ', $input);
        $input = str_replace($specialChars, '', $input);
        $input = str_replace(array('%20', '+'), '-', $input);
        $input = preg_replace('/[\r\n\t -]+/', '-', $input);
        return trim($input ?? 'file', '.-_');
    }

    /**
     * This exists because: The filename fallback must only contain ASCII characters. at /elabftw/vendor/symfony/http-foundation/HeaderUtils.php:173
     */
    public static function toAscii(string $input): string
    {
        // mb_convert_encoding will replace invalid characters with ?, but we want _ instead
        return str_replace('?', '_', mb_convert_encoding(self::forFilesystem($input), 'ASCII', 'UTF-8'));
    }

    public static function intOrNull(string|int $input): ?int
    {
        $res = (int) $input;
        if ($res === 0) {
            return null;
        }
        return $res;
    }

    /**
     * An hexit is an hexadecimal digit: 0 to 9 and a to f
     */
    public static function hexits(string $input): string
    {
        $res = preg_replace('/[^[:xdigit:]]/', '', $input);
        if ($res === null) {
            return '';
        }
        return $res;
    }

    /**
     * Sanitize body with a list of allowed html tags.
     *
     * @param string $input Body to sanitize
     * @return string The sanitized body or empty string if there is no input
     */
    public static function body(?string $input = null): string
    {
        if ($input === null) {
            return '';
        }
        // use strlen() instead of mb_strlen() because we want the size in bytes
        if (strlen($input) > self::MAX_BODY_SIZE) {
            throw new ImproperActionException('Content is too big! Cannot save!');
        }
        // get blacklist IDs from HTML files
        $blacklistIds = self::getBlacklistIdsFromHtmlFiles('/elabftw/src/templates');
        // create base config for html5
        $config = HTMLPurifier_HTML5Config::createDefault();
        // enable ids
        $config->set('Attr.EnableID', true);
        $config->set('Attr.IDBlacklist', $blacklistIds);
        // allow only certain elements
        $config->set('HTML.Allowed', 'div[class|id|style],br[class|id|style],p[class|id|style],sub[class|id|style],img[src|id|class|style|width|height|alt],sup[class|id|style],strong[class|id|style],b[class|id|style],em[class|id|style],u[class|id|style],a[href|hreflang|class|id|style|rel|type],s[class|id|style],span[class|id|style],ul[class|id|style],li[class|id|style|value],ol[class|id|style|reversed|start|type],dl[class|id|style],dt[class|id|style],dd[class|id|style],blockquote[class|id|style|cite],h1[class|id|style],h2[class|id|style],h3[class|id|style],h4[class|id|style],h5[class|id|style],h6[class|id|style],hr[class|id|style],table[class|id|style],tr[class|id|style],td[class|id|style|colspan|rowspan|headers],th[class|id|style|colspan|rowspan|abbr|headers|scope],code[class|id|style],video[class|id|src|controls|controlslist|style|height|width|disablepictureinpicture|disableremoteplayback|loop|muted|poster|preload],audio[class|id|style|src|controls|autoplay|controlslist|disableremoteplayback|loop|muted|preload],pre[class|id|style],details[class|id|style|open|name],summary[class|id|style],caption[class|id|style],figure[class|id|style],figcaption[class|id|style],abbr[|class|id|style|title],aside[class|id|style],bdi[class|id|style|dir],cite[class|id|style],col[class|id|span|style],data[class|id|style|value],del[class|id|style|cite|datetime],dfn[class|style|title|id],ins[class|id|style|cite|datetime],kbd[class|id|style],mark[class|id|style],q[class|id|style|cite],samp[class|id|style],tbody[class|id|style],tfoot[class|id|style],thead[class|id|style],time[class|id|style|datetime],var[class|id|style],wbr[class|id|style],small[class|id|style],input[class|id|style|alt|autocapitalize|autocomplete|checked|disabled|form|height|list|max|maxlength|min|minlength|name|pattern|placeholder|popovertarget|popovertargetaction|readonly|required|size|src|step|type|value|width],label[class|id|style|for]');
        $config->set('HTML.TargetBlank', true);
        // configure the cache for htmlpurifier
        $tmpDir = FsTools::getCacheFolder('purifier');
        $config->set('Cache.SerializerPath', $tmpDir);
        // allow "display" css attribute
        $config->set('CSS.AllowTricky', true);
        // allow any image size, see #3800
        $config->set('CSS.MaxImgLength', null);
        $config->set('HTML.MaxImgLength', null);
        // permit form fields
        $config->set('HTML.Trusted', true);
        // allow 'data-table-sort' attribute to indicate that a table shall be sortable by js
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addAttribute('table', 'data-table-sort', 'Enum#true');
            $def->addAttribute('td', 'headers', 'NMTOKENS');
            $def->addAttribute('th', 'headers', 'NMTOKENS');
        }

        $purifier = new HTMLPurifier($config);
        return $purifier->purify($input);
    }

    /**
     * Recursively scan HTML files in the directory to get a list of unique IDs for blacklist
     *
     * @param string $directory The path to the directory containing HTML files
     * @return array List of IDs to blacklist
     */
    private static function getBlacklistIdsFromHtmlFiles(string $directory): array
    {
        $ids = array();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'html') {
                $content = file_get_contents($file->getRealPath());
                if ($content != false) {
                    preg_match_all('/id\s*=\s*"([^"]+)"/i', $content, $matches);
                    if (!empty($matches[1])) {
                        $ids = array_merge($ids, $matches[1]);
                    }
                }
            }
        }
        return array_values(array_unique($ids)); // Remove duplicates and reindex
    }
}
