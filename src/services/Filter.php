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
        $htmlcommon = 'lang|title|translate';
        $mathmlcommon = 'displaystyle|mathbackground|mathcolor|mathsize|scriptlevel';
        $config->set('HTML.Allowed', implode(',', array(
            '*[autofocus|class|dir|id|style|tabindex]',
            'a[href|hreflang|rel|type|' . $htmlcommon . ']',
            'abbr[title|' . $htmlcommon . ']',
            'address[' . $htmlcommon . ']',
            'aside[' . $htmlcommon . ']',
            'b[' . $htmlcommon . ']',
            'bdi[dir|' . $htmlcommon . ']',
            'blockquote[cite|' . $htmlcommon . ']',
            'br[' . $htmlcommon . ']',
            'caption[' . $htmlcommon . ']',
            'cite[' . $htmlcommon . ']',
            'code[' . $htmlcommon . ']',
            'col[span|' . $htmlcommon . ']',
            'colgroup[span|' . $htmlcommon . ']',
            'data[value|' . $htmlcommon . ']',
            'dd[' . $htmlcommon . ']',
            'del[cite|datetime|' . $htmlcommon . ']',
            'details[open|name|' . $htmlcommon . ']',
            'dfn[' . $htmlcommon . ']',
            'div[' . $htmlcommon . ']',
            'dl[' . $htmlcommon . ']',
            'dt[' . $htmlcommon . ']',
            'em[' . $htmlcommon . ']',
            'figcaption[' . $htmlcommon . ']',
            'figure[' . $htmlcommon . ']',
            'h1[' . $htmlcommon . ']',
            'h2[' . $htmlcommon . ']',
            'h3[' . $htmlcommon . ']',
            'h4[' . $htmlcommon . ']',
            'h5[' . $htmlcommon . ']',
            'h6[' . $htmlcommon . ']',
            'hgroup[' . $htmlcommon . ']',
            'i[' . $htmlcommon . ']',
            'img[src|width|height|alt|' . $htmlcommon . ']',
            'ins[cite|datetime|' . $htmlcommon . ']',
            'kbd[' . $htmlcommon . ']',
            'li[value|' . $htmlcommon . ']',
            'mark[' . $htmlcommon . ']',
            'ol[reversed|start|type|' . $htmlcommon . ']',
            'p[' . $htmlcommon . ']',
            'pre[' . $htmlcommon . ']',
            'q[cite|' . $htmlcommon . ']',
            's[' . $htmlcommon . ']',
            'samp[' . $htmlcommon . ']',
            'section[' . $htmlcommon . ']',
            'small[' . $htmlcommon . ']',
            'span[' . $htmlcommon . ']',
            'strong[' . $htmlcommon . ']',
            'sub[' . $htmlcommon . ']',
            'summary[' . $htmlcommon . ']',
            'sup[' . $htmlcommon . ']',
            'table[' . $htmlcommon . ']',
            'tbody[' . $htmlcommon . ']',
            'td[colspan|rowspan|headers|' . $htmlcommon . ']',
            'tfoot[' . $htmlcommon . ']',
            'th[colspan|rowspan|abbr|headers|scope|' . $htmlcommon . ']',
            'thead[' . $htmlcommon . ']',
            'time[datetime|' . $htmlcommon . ']',
            'tr[' . $htmlcommon . ']',
            'ul[' . $htmlcommon . ']',
            'var[' . $htmlcommon . ']',
            'video[src|controls|controlslist|height|width|playsinline|loop|muted|poster|preload|' . $htmlcommon . ']',
            'wbr[' . $htmlcommon . ']',
            'annotation[encoding|' . $mathmlcommon . ']',
            'annotation-xml[encoding|' . $mathmlcommon . ']',
            'maction[actiontype|selection|' . $mathmlcommon . ']',
            'math[display|alttext|' . $mathmlcommon . ']',
            'merror[' . $mathmlcommon . ']',
            'mfrac[linethickness|' . $mathmlcommon . ']',
            'mi[mathvariant|' . $mathmlcommon . ']',
            'mmultiscripts[' . $mathmlcommon . ']',
            'mn[' . $mathmlcommon . ']',
            'mo[form|fence|separator|lspace|rspace|stretchy|symmetric|maxsize|minsize|largeop|movablelimits|' . $mathmlcommon . ']',
            'mover[accent|' . $mathmlcommon . ']',
            'mpadded[width|height|depth|lspace|voffset' . $mathmlcommon . ']',
            'mphantom[' . $mathmlcommon . ']',
            'mprescripts[' . $mathmlcommon . ']',
            'mroot[' . $mathmlcommon . ']',
            'mrow[' . $mathmlcommon . ']',
            'ms[' . $mathmlcommon . ']',
            'mspace[width|height|depth|' . $mathmlcommon . ']',
            'msqrt[' . $mathmlcommon . ']',
            'mstyle[' . $mathmlcommon . ']',
            'msub[' . $mathmlcommon . ']',
            'msubsup[' . $mathmlcommon . ']',
            'msup[' . $mathmlcommon . ']',
            'mtable[' . $mathmlcommon . ']',
            'mtd[colspan|rowspan|' . $mathmlcommon . ']',
            'mtext[' . $mathmlcommon . ']',
            'mtr[' . $mathmlcommon . ']',
            'munder[accentunder|' . $mathmlcommon . ']',
            'munderover[accent|accentunder|' . $mathmlcommon . ']',
            'semantics[' . $mathmlcommon . ']',
        )));
        // allow certain global attributes
        $config->set('HTML.TargetBlank', true);
        // default text direction; TODO change this value based on default language
        $config->set('Attr.DefaultTextDir', 'ltr');
        // remove nonspecific spans
        $config->set('AutoFormat.RemoveSpansWithoutAttributes', true);
        // allow safe embeds
        $config->set('HTML.SafeObject', true);
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
            /**
             * MathML definitions
             * DTD reference for MathML 3: https://www.w3.org/Math/DTD/mathml3/mathml3.dtd
             * Valid elements and attributes for MathML Core: https://www.w3.org/TR/mathml-core/
             * Mozilla lists additional attributes as valid: https://developer.mozilla.org/en-US/docs/Web/MathML/Reference/Attribute
             */
            $MathExpression = 'semantics|mi|mn|mo|mtext|mspace|ms|mrow|mfrac|msqrt|mroot|mstyle|merror|mpadded|mphantom|msub|msup|msubsup|munder|mover|munderover|mmultiscripts|mtable|maction';
            $MathMLCommonAttributes = array(
                'autofocus' => 'Bool',
                'class' => 'Class',
                'dir' => 'Enum#ltr|rtl',
                'displaystyle' => 'Bool',
                'id' => 'ID',
                'mathbackground' => 'Color',
                'mathcolor' => 'Color',
                'mathsize' => 'Length',
                'scriptlevel' => 'Number',
                // technically tabindex is supposed to be positive integer, 0, or -1, but tabindex attribute for predefined HTML elements all are declared as 'Number' which allows only positive integers
                'tabindex' => 'Number',
                'href' => 'URI',
                'intent' => 'Text',
                // string should actually be NCNAME: https://w3c.github.io/mathml/#mixing_intent_grammar
                'arg' => 'Text',
            );
            $def->addElement(
                'annotation',
                false,
                'Required: #PCDATA',
                'Style',
                $MathMLCommonAttributes + array('encoding' => 'Text')
            );
            $def->addElement(
                'annotation-xml',
                false,
                'Optional: ' . $MathExpression,
                'Style',
                $MathMLCommonAttributes + array('encoding' => 'Text')
            );
            $def->addElement(
                'maction',
                false,
                'Required: ' . $MathExpression,
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'math',
                'Block',
                'Optional: ' . $MathExpression,
                'Style',
                $MathMLCommonAttributes + array(
                    'display' => 'Enum#block,inline',
                    'alttext' => 'Text',
                )
            );
            $def->addElement(
                'math',
                'Inline',
                'Optional: ' . $MathExpression,
                'Style',
                $MathMLCommonAttributes + array(
                    'display' => 'Enum#block,inline',
                    'alttext' => 'Text',
                )
            );
            $def->addElement(
                'merror',
                false,
                'Optional: ' . $MathExpression,
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'mfrac',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '))',
                'Style',
                $MathMLCommonAttributes + array('linethickness' => 'Length',)
            );
            $def->addElement(
                'mi',
                false,
                'Optional: #PCDATA',
                'Style',
                $MathMLCommonAttributes + array('mathvariant' => 'Enum#normal')
            );
            $def->addElement(
                'mmultiscripts',
                false,
                'Custom: ((' . $MathExpression . '),((' . $MathExpression . '),(' . $MathExpression . '))*,(mprescripts,((' . $MathExpression . '),(' . $MathExpression . '))*)?)',
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'mn',
                false,
                'Optional: #PCDATA',
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'mo',
                false,
                'Optional: #PCDATA',
                'Style',
                $MathMLCommonAttributes + array(
                    'form' => 'Enum#infix|prefix|postfix',
                    'fence' => 'Bool',
                    'separator' => 'Bool',
                    'lspace' => 'Length',
                    'rspace' => 'Length',
                    'stretchy' => 'Bool',
                    'symmetric' => 'Bool',
                    'maxsize' => 'Length',
                    'minsize' => 'Length',
                    'largeop' => 'Bool',
                    'movablelimits' => 'Bool',
                )
            );
            $def->addElement(
                'mover',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '))',
                'Style',
                $MathMLCommonAttributes + array('accent' => 'Bool')
            );
            $def->addElement(
                'mpadded',
                false,
                'Optional: ' . $MathExpression,
                'Style',
                $MathMLCommonAttributes + array(
                    'width' => 'Length',
                    'height' => 'Length',
                    'depth' => 'Length',
                    'lspace' => 'Length',
                    'voffset' => 'Length',
                )
            );
            $def->addElement(
                'mphantom',
                false,
                'Optional: ' . $MathExpression,
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'mprescripts',
                false,
                'Empty',
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'mroot',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '))',
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'mrow',
                false,
                'Optional: ' . $MathExpression,
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'ms',
                false,
                'Optional: #PCDATA',
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'mspace',
                false,
                'Empty',
                'Style',
                $MathMLCommonAttributes + array(
                    'width' => 'Length',
                    'height' => 'Length',
                    'depth' => 'Length',
                )
            );
            $def->addElement(
                'msqrt',
                false,
                'Optional: ' . $MathExpression,
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'mstyle',
                false,
                'Optional: ' . $MathExpression,
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'msub',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '))',
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'msubsup',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '),(' . $MathExpression . '))',
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'msup',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '))',
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'mtable',
                false,
                'Optional: mtr',
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'mtd',
                false,
                'Optional: ' . $MathExpression,
                'Style',
                $MathMLCommonAttributes + array(
                    'columnspan' => 'Number',
                    'rowspan' => 'Number',
                )
            );
            $def->addElement(
                'mtext',
                false,
                'Optional: #PCDATA',
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'mtr',
                false,
                'Optional: mtd',
                'Style',
                $MathMLCommonAttributes
            );
            $def->addElement(
                'munder',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '))',
                'Style',
                $MathMLCommonAttributes + array('accentunder' => 'Bool')
            );
            $def->addElement(
                'munderover',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '),(' . $MathExpression . '))',
                'Style',
                $MathMLCommonAttributes + array(
                    'accent' => 'Bool',
                    'accentunder' => 'Bool',
                )
            );
            $def->addElement(
                'semantics',
                false,
                'Custom: ((' . $MathExpression . '),(annotation|annotation-xml)*)',
                'Style',
                $MathMLCommonAttributes
            );
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
