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
        $htmlcommon = 'autofocus|dir|lang|title|translate|tabindex';
        // $mathmlcommon = 'href|xref';
        $config->set('HTML.Allowed', implode(',', array(
            '*[class|id|style]',
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
            'annotation',
            'annotation-xml',
            'apply',
            'bind',
            'bvar',
            'cbytes',
            'cerror',
            'ci',
            'cn',
            'cs',
            'csymbol',
            'maction',
            'maligngroup',
            'malignmark',
            'math[display]',
            'menclose',
            'mfenced',
            'mfrac',
            'merror',
            'mglyph',
            'mi',
            'mlabeledtr',
            'mlongdiv',
            'mmultiscripts',
            'mo',
            'mn',
            'mover',
            'mpadded',
            'mphantom',
            'mprescripts',
            'mroot',
            'mrow',
            'ms',
            'mscarries',
            'mscarry',
            'msgroup',
            'msline',
            'mspace',
            'msqrt',
            'msrow',
            'mstack',
            'mstyle',
            'msub',
            'msubsup',
            'msup',
            'mtable',
            'mtext',
            'mtd',
            'mtr',
            'munder',
            'munderover',
            'none',
            'semantics',
            'share',
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
             */
            // MathML variable definitions
            $CommonAtt = array(
                'id' => 'ID',
                'xref' => 'Text',
                'class' => 'NMTOKENS',
                'style' => 'Text',
                'href' => 'URI',
            );
            $DefEncAtt = array(
                'encoding' => 'Text',
                'definitionURL' => 'URI',
            );
            $CommonPresAtt = array(
                'mathcolor' => 'Color',
                'mathbackground' => 'Color',
            );
            $TokenAtt = array(
                'mathvariant' => 'Enum#normal,bold,italic,bold-italic,double-struck,bold-fraktur,script,bold-script,fraktur,sans-serif,bold-sans-serif,sans-serif-italic,sans-serif-bold-italic,monospace,initial,tailed,looped,stretched',
                'mathsize' => 'Enum#small,normal,big',
                // 'mathsize' => 'Length',
                'dir' => 'Enum#ltr,rtl',
            );
            $ContExp = 'semantics|cn|ci|csymbol|apply|bind|share|cerror|cbytes|cs';
            $TokenExpression = 'mi|mn|mo|mtext|mspace|ms';
            $MalignExpression = 'maligngroup|malignmark';
            $PresentationExpression = $TokenExpression . '|mrow|mfrac|msqrt|mroot|mstyle|merror|mpadded|mphantom|mfenced|menclose|msub|msup|msubsup|munder|mover|munderover|mmultiscripts|mtable|mstack|mlongdiv|maction|' . $MalignExpression;
            $MathExpression = $PresentationExpression . '|' . $ContExp;
            $MstackExpression = $MathExpression . '|mscarries|msline|msrow|msgroup';
            $ImpliedMrow = '(' . $MathExpression . ')*';
            // add MathML elements
            $def->addElement(
                'annotation',
                false,
                'Required: #PCDATA',
                null,
                array(
                    'definitionURL' => 'URI',
                    'encoding' => 'Text',
                    'cd' => 'Text',
                    'name' => 'Text',
                    'src' => 'URI',
                )
            );
            $def->addElement(
                'annotation-xml',
                false,
                'Optional: ' . $MathExpression,
                null,
                array(
                    'definitionURL' => 'URI',
                    'encoding' => 'Text',
                    'cd' => 'Text',
                    'name' => 'Text',
                    'src' => 'URI',
                )
            );
            $def->addElement(
                'apply',
                false,
                'Required: ' . $ContExp,
                null,
                $CommonAtt
            );
            $def->addElement(
                'bind',
                false,
                'Custom: (' . $ContExp . '),(bvar)*,(' . $ContExp . ')',
                null,
                $CommonAtt
            );
            $def->addElement(
                'bvar',
                false,
                'Custom: (ci|semantics)',
                null,
                $CommonAtt
            );
            $def->addElement(
                'cbytes',
                false,
                'Required: #PCDATA',
                null,
                $CommonAtt
            );
            $def->addElement(
                'cerror',
                false,
                'Custom: (csymbol,(' . $ContExp . ')*)',
                null,
                $CommonAtt
            );
            $def->addElement(
                'ci',
                false,
                'Required: #PCDATA',
                null,
                $CommonAtt + array('type' => 'Enum#integer,rational,real,complex,complex-polar,complex-cartesian,constant,function,vector,list,set,matrix')
            );
            $def->addElement(
                'cn',
                false,
                'Required: #PCDATA',
                null,
                $CommonAtt + array('type*' => 'Enum#integer,real,double,hexdouble')
            );
            $def->addElement(
                'cs',
                false,
                'Required: #PCDATA',
                null,
                $CommonAtt
            );
            $def->addElement(
                'csymbol',
                false,
                'Required: #PCDATA',
                null,
                $CommonAtt + array('cd*' => 'Text')
            );
            $def->addElement(
                'maction',
                false,
                'Required: ' . $MathExpression,
                null,
                // attributes
            );
            $def->addElement(
                'maligngroup',
                false,
                'Empty',
                null,
                $CommonAtt + $CommonPresAtt + array('groupalign' => 'Enum#left,center,right,decimalpoint')
            );
            $def->addElement(
                'malignmark',
                false,
                'Empty',
                null,
                $CommonAtt + $CommonPresAtt + array('edge' => 'Enum#left,right')
            );
            $def->addElement(
                'math',
                'Block',
                'Optional: ' . $MathExpression,
                null,
                $CommonAtt + array(
                    'display' => 'Enum#block,inline',
                    'maxwidth' => 'Length',
                    'overflow' => 'Enum#linebreak,scroll,elide,truncate,scale',
                    'altimg' => 'URI',
                    'altimg-width' => 'Length',
                    'altimg-height' => 'Length',
                    'altimg-valign' => 'Length',
                    // 'altimg-valign' => 'Enum#top,middle,bottom',
                    'alttext' => 'Text',
                    'cdgroup' => 'URI',
                )
            );
            $def->addElement(
                'math',
                'Inline',
                'Optional: ' . $MathExpression,
                null,
                $CommonAtt + array(
                    'display' => 'Enum#block,inline',
                    'maxwidth' => 'Length',
                    'overflow' => 'Enum#linebreak,scroll,elide,truncate,scale',
                    'altimg' => 'URI',
                    'altimg-width' => 'Length',
                    'altimg-height' => 'Length',
                    'altimg-valign' => 'Length',
                    // 'altimg-valign' => 'Enum#top,middle,bottom',
                    'alttext' => 'Text',
                    'cdgroup' => 'URI',
                )
            );
            $def->addElement(
                'menclose',
                false,
                'Custom: ' . $ImpliedMrow,
                null,
                $CommonAtt + $CommonPresAtt + array('notation' => 'Text')
            );
            $def->addElement(
                'mfenced',
                false,
                'Optional: ' . $MathExpression,
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'open' => 'Text',
                    'close' => 'Text',
                    'separators' => 'Text',
                )
            );
            $def->addElement(
                'mfrac',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '))',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'linethickness' => 'Length',
                    // 'linethickness' => 'Enum#thin,medium,thick',
                    'numalign' => 'Enum#left,center,right',
                    'denomalign' => 'Enum#left,center,right',
                    'bevelled' => 'Bool',
                )
            );
            $def->addElement(
                'merror',
                false,
                'Custom: ' . $ImpliedMrow,
                null,
                $CommonAtt + $CommonPresAtt
            );
            $def->addElement(
                'mglyph',
                false,
                'Empty',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'src' => 'URI',
                    'width' => 'Length',
                    'height' => 'Length',
                    'valign' => 'Length',
                    'alt' => 'Text',
                )
            );
            $def->addElement(
                'mi',
                false,
                'Optional: mglyph|malignmark|#PCDATA',
                null,
                $CommonAtt + $CommonPresAtt + $TokenAtt
            );
            $def->addElement(
                'mlabeledtr',
                false,
                'Required: mtd',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'rowalign' => 'Enum#top,bottom,center,baseline,axis',
                    'columnalign' => 'Text',
                    'groupalign' => 'Text',
                )
            );
            $def->addElement(
                'mlongdiv',
                false,
                'Custom: (' . $MstackExpression . '),(' . $MstackExpression . '),($MstackExpression)+',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'position' => 'Number',
                    'shift' => 'Number',
                    'longdivstyle' => 'Enum#lefttop,stackedrightright,mediumstackedrightright,shortstackedrightright,righttop,left/\right,left)(right,:right=right,stackedleftleft,stackedleftlinetop',
                )
            );
            $def->addElement(
                'mmultiscripts',
                false,
                // 'Custom: (' . $MathExpression . '),(' . $MultiScriptExpression . ')*,(mprescripts|(' . $MultiScriptExpression . ')*)?',
                'Empty',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'subscriptshift' => 'Length',
                    'superscriptshift' => 'Length',
                )
            );
            $def->addElement(
                'mn',
                false,
                'Optional: mglyph|malignmark|#PCDATA',
                null,
                $CommonAtt + $CommonPresAtt + $TokenAtt
            );
            $def->addElement(
                'mo',
                false,
                'Optional: mglyph|malignmark|#PCDATA',
                null,
                $CommonAtt + $CommonPresAtt + $TokenAtt
            );
            $def->addElement(
                'mover',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '))',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'accent' => 'Bool',
                    'align' => 'Enum#left,right,center',
                )
            );
            $def->addElement(
                'mpadded',
                false,
                'Custom: ' . $ImpliedMrow,
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'height' => 'Text',
                    'depth' => 'Text',
                    'width' => 'Text',
                    'lspace' => 'Text',
                    'voffset' => 'Text',
                )
            );
            $def->addElement(
                'mphantom',
                false,
                'Custom: ' . $ImpliedMrow,
                null,
                $CommonAtt + $CommonPresAtt
            );
            $def->addElement(
                'mprescripts',
                false,
                'Empty',
                null,
                $CommonAtt + $CommonPresAtt
            );
            $def->addElement(
                'mroot',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '))',
                null,
                $CommonAtt + $CommonPresAtt
            );
            $def->addElement(
                'mrow',
                false,
                'Optional: ' . $MathExpression,
                null,
                $CommonAtt + $CommonPresAtt + array('dir' => 'Enum#ltr,rtl')
            );
            $def->addElement(
                'ms',
                false,
                'Optional: mglyph|malignmark|#PCDATA',
                null,
                $CommonAtt + $CommonPresAtt + $TokenAtt + array(
                    'lquote' => 'Text',
                    'rquote' => 'Text',
                )
            );
            $def->addElement(
                'mscarries',
                false,
                // 'Optional: ' . $MsrowExpression . '|mscarry',
                'Empty',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'position' => 'Number',
                    'location' => 'Enum#w,nw,n,ne,e,se,s,sw',
                    'crossout' => 'Text',
                    'scriptsizemultiplier' => 'Number',
                )
            );
            $def->addElement(
                'mscarry',
                false,
                // 'Optional: ' . $MsrowExpression,
                'Empty',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'location' => 'Enum#w,nw,n,ne,e,se,s,sw',
                    'crossout' => 'Text',
                )
            );
            $def->addElement(
                'msgroup',
                false,
                'Optional: ' . $MstackExpression,
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'position' => 'Number',
                    'shift' => 'Number',
                )
            );
            $def->addElement(
                'msline',
                false,
                'Empty',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'position' => 'Number',
                    'length' => 'Number',
                    'leftoverhang' => 'Length',
                    'rightoverhang' => 'Length',
                    'mslinethickness' => 'Length',
                    // 'mslinethickness' => 'Enum#thin,medium,thick',
                )
            );
            $def->addElement(
                'mspace',
                false,
                'Empty',
                null,
                $CommonAtt + $CommonPresAtt + $TokenAtt + array(
                    'width' => 'Length',
                    'height' => 'Length',
                    'depth' => 'Length',
                    'linebreak' => 'Enum#auto,newline,nobreak,goodbreak,badbreak,indentingnewline',
                    'indentalign' => 'Enum#left,center,right,auto,id',
                    'indentshift' => 'Length',
                    'indenttarget' => 'Text',
                    'indentalignfirst' => 'Enum#left,center,right,auto,id,indentalign',
                    'indentshiftfirst' => 'Length',
                    // 'indentshiftfirst' => 'Enum#indentshift',
                    'indentalignlast' => 'Enum#left,center,right,auto,id,indentalign',
                    'indentshiftlast' => 'Length',
                    // 'indentshiftlast' => 'Enum#indentshift',
                )
            );
            $def->addElement(
                'msqrt',
                false,
                'Custom: (' . $ImpliedMrow . ')',
                null,
                $CommonAtt + $CommonPresAtt
            );
            $def->addElement(
                'msrow',
                false,
                // 'Optional: ' . $MsrowExpression,
                'Empty',
                null,
                $CommonAtt + $CommonPresAtt + array('position' => 'Number')
            );
            $def->addElement(
                'mstack',
                false,
                'Optional: ' . $MstackExpression,
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'align' => 'Text',
                    'stackalign' => 'Enum#left,center,right,decimalpoint',
                    'charalign' => 'Enum#left,center,right',
                    'charspacing' => 'Length',
                    // 'charspacing' => 'Enum#loose,medium,tight',
                )
            );
            $def->addElement(
                'mstyle',
                false,
                'Custom: ' . $ImpliedMrow,
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'scriptlevel' => 'Length',
                    'displaystyle' => 'Bool',
                    'scriptsizemultiplier' => 'Number',
                    'scriptminsize' => 'Length',
                    'infixlinebreakstyle' => 'Enum#before,after,duplicate',
                    'decimalpoint' => 'Character',
                    'accent' => 'Bool',
                    'accentunder' => 'Bool',
                    'align' => 'Enum#left,right,center',
                    'alignmentscope' => 'Text',
                    'bevelled' => 'Bool',
                    'charalign' => 'Enum#left,center,right',
                    'charspacing' => 'Length',
                    // 'charspacing' => 'Enum#loose,medium,tight',
                    'close' => 'Text',
                    'columnalign' => 'Text',
                    'columnlines' => 'Text',
                    'columnspacing' => 'Text',
                    'columnspan' => 'Number',
                    'columnwidth' => 'Text',
                    'crossout' => 'Text',
                    'denomalign' => 'Enum#left,center,right',
                    'depth' => 'Length',
                    'dir' => 'Enum#ltr,rtl',
                    'edge' => 'Enum#left,right',
                    'equalcolumns' => 'Bool',
                    'equalrows' => 'Bool',
                    'fence' => 'Bool',
                    'form' => 'Enum#prefix,infix,postfix',
                    'frame' => 'Enum#none,solid,dashed',
                    'framespacing' => 'Text',
                    'groupalign' => 'Text',
                    'height' => 'Length',
                    'indentalign' => 'Enum#left,center,right,auto,id',
                    'indentalignfirst' => 'Enum#left,center,right,auto,id,indentalign',
                    'indentalignlast' => 'Enum#left,center,right,auto,id,indentalign',
                    'indentshift' => 'Length',
                    'indentshiftfirst' => 'Length',
                    // 'indentshiftfirst' => 'Enum#indentshift',
                    'indentshiftlast' => 'Length',
                    // 'indentshiftlast' => 'Enum#indentshift',
                    'indenttarget' => 'Text',
                    'largeop' => 'Bool',
                    'leftoverhang' => 'Length',
                    'length' => 'Number',
                    'linebreak' => 'Enum#auto,newline,nobreak,goodbreak,badbreak',
                    'linebreakmultichar' => 'Text',
                    'linebreakstyle' => 'Enum#before,after,duplicate,infixlinebreakstyle',
                    'lineleading' => 'Length',
                    'linethickness' => 'Length',
                    // 'linethickness' => 'Enum#thin,medium,thick',
                    'location' => 'Enum#w,nw,n,ne,e,se,s,sw',
                    'longdivstyle' => 'Enum#lefttop,stackedrightright,mediumstackedrightright,shortstackedrightright,righttop,left/\right,left)(right,:right=right,stackedleftleft,stackedleftlinetop',
                    'lquote' => 'Text',
                    'lspace' => 'Length',
                    'mathsize' => 'Enum#small,normal,big',
                    // 'mathsize' => 'Length',
                    'mathvariant' => 'Enum#normal,bold,italic,bold-italic,double-struck,bold-fraktur,script,bold-script,fraktur,sans-serif,bold-sans-serif,sans-serif-italic,sans-serif-bold-italic,monospace,initial,tailed,looped,stretched',
                    'maxsize' => 'Length',
                    // 'maxsize' => 'Enum#infinity',
                    'minlabelspacing' => 'Length',
                    'minsize' => 'Length',
                    'movablelimits' => 'Bool',
                    'mslinethickness' => 'Length',
                    // 'mslinethickness' => 'Enum#thin,medium,thick',
                    'notation' => 'Text',
                    'numalign' => 'Enum#left,center,right',
                    'open' => 'Text',
                    'position' => 'Number',
                    'rightoverhang' => 'Length',
                    'rowalign' => 'Text',
                    'rowlines' => 'Text',
                    'rowspacing' => 'Text',
                    'rowspan' => 'Number',
                    'rquote' => 'Text',
                    'rspace' => 'Length',
                    'selection' => 'Number',
                    'separator' => 'Bool',
                    'separators' => 'Text',
                    'shift' => 'Number',
                    'side' => 'Enum#left,right,leftoverlap,rightoverlap',
                    'stackalign' => 'Enum#left,center,right,decimalpoint',
                    'stretchy' => 'Bool',
                    'subscriptshift' => 'Length',
                    'superscriptshift' => 'Length',
                    'symmetric' => 'Bool',
                    'valign' => 'Length',
                    'width' => 'Length',
                )
            );
            $def->addElement(
                'msub',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '))',
                null,
                $CommonAtt + $CommonPresAtt + array('subscriptshift' => 'Length')
            );
            $def->addElement(
                'msubsup',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '),(' . $MathExpression . '))',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'subscriptshift' => 'Length',
                    'superscriptshift' => 'Length',
                )
            );
            $def->addElement(
                'msup',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '))',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'subscriptshift' => 'Length',
                    'superscriptshift' => 'Length',
                )
            );
            $def->addElement(
                'mtable',
                false,
                // 'Optional: ' . $TableRowExpression,
                'Empty',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'align' => 'Text',
                    'rowalign' => 'Text',
                    'columnalign' => 'Text',
                    'groupalign' => 'Text',
                    'alignmentscope' => 'Text',
                    'columnwidth' => 'Text',
                    'width' => 'Enum#auto',
                    // 'width' => 'Length',
                    'rowspacing' => 'Text',
                    'columnspacing' => 'Text',
                    'rowlines' => 'Text',
                    'columnlines' => 'Text',
                    'frame' => 'Enum#none,solid,dashed',
                    'framespacing' => 'Text',
                    'equalrows' => 'Bool',
                    'equalcolumns' => 'Bool',
                    'displaystyle' => 'Bool',
                    'side' => 'Enum#left,right,leftoverlap,rightoverlap',
                    'minlabelspacing' => 'Length',
                )
            );
            $def->addElement(
                'mtext',
                false,
                'Optional: mglyph|malignmark|#PCDATA',
                null,
                $CommonAtt + $CommonPresAtt + $TokenAtt
            );
            $def->addElement(
                'mtd',
                false,
                'Required: ' . $ImpliedMrow,
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'rowspan' => 'Number',
                    'columnspan' => 'Number',
                    'rowalign' => 'Enum#top,bottom,center,baseline,axis',
                    'columnalign' => 'Enum#left,center,right',
                    'groupalign' => 'Text',
                )
            );
            $def->addElement(
                'mtr',
                false,
                'Optional: mtd',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'rowalign' => 'Enum#top,bottom,center,baseline,axis',
                    'columnalign' => 'Text',
                    'groupalign' => 'Text',
                )
            );
            $def->addElement(
                'munder',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '))',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'accentunder' => 'Bool',
                    'align' => 'Enum#left,right,center',
                )
            );
            $def->addElement(
                'munderover',
                false,
                'Custom: ((' . $MathExpression . '),(' . $MathExpression . '),(' . $MathExpression . '))',
                null,
                $CommonAtt + $CommonPresAtt + array(
                    'accent' => 'Bool',
                    'accentunder' => 'Bool',
                    'align' => 'Enum#left,right,center',
                )
            );
            $def->addElement(
                'none',
                false,
                'Empty',
                null,
                $CommonAtt + $CommonPresAtt
            );
            $def->addElement(
                'semantics',
                false,
                'Custom: ((' . $MathExpression . '),(annotation|annotation-xml)*)',
                null,
                $CommonAtt + $DefEncAtt + array(
                    'cd' => 'Text',
                    'name' => 'Text',
                )
            );
            $def->addElement(
                'share',
                false,
                'Empty',
                null,
                $CommonAtt + array('src', 'URI')
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
