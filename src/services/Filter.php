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
        $config->set('HTML.Allowed', '[class|dir|id|itemid|itemprop|itemref|itemscope|itemtype|lang|role|style|tabindex|title|translate],div,br,p,sub,img[src|width|height|alt],sup,strong,b,em,u,a[href|hreflang|rel|type],s,span,ul,li[value],ol[reversed|start|type],dl,dt,dd,blockquote[cite],h1,h2,h3,h4,h5,h6,hr,table,tr,td[colspan|rowspan|headers],th[colspan|rowspan|abbr|headers|scope],code,video[src|controls|controlslist|height|width|disablepictureinpicture|disableremoteplayback|loop|muted|poster|preload],audio[src|controls|autoplay|controlslist|disableremoteplayback|loop|muted|preload],pre,details[open|name],summary,caption,figure,figcaption,abbr[title],aside,bdi[dir],cite,col[span|style],data[value],del[cite|datetime],dfn[title|id],ins[cite|datetime],kbd,mark,q[cite],samp,tbody,tfoot,thead,time[datetime],var,wbr,small,input[alt|autocapitalize|autocomplete|checked|disabled|form|height|list|max|maxlength|min|minlength|name|pattern|placeholder|popovertarget|popovertargetaction|readonly|required|size|src|step|type|value|width],label[for]');
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
        // MathML element definition arrays
        // $MMLIDTYPE = 'ID';
        // $MMLIDREFTYPE = 'ID';
        // $MalignExpression = array('maligngroup', 'malignmark');
        // $TokenExpression = array('mi', 'mn', 'mo', 'mtext', 'mspace', 'ms');
        // $PresentationExpression = $TokenExpression + $MalignExpression + array('mrow', 'mfrac', 'msqrt', 'mroot', 'mstyle', 'merror', 'mpadded', 'mphantom', 'mfenced', 'menclose', 'msub', 'msup', 'msubsup', 'munder', 'mover', 'munderover', 'mmultiscripts', 'mtable', 'mstack', 'mlongdiv', 'maction');
        // $cnContent = '(#PCDATA|mglyph|sep|' + implode('|', $PresentationExpression) + ')*';
        // $ciContent = '(#PCDATA|mglyph|' + implode('|', $PresentationExpression) + ')*';
        // $csymbolContent = '(#PCDATA|mglyph|' + implode('|', $PresentationExpression) + ')*';
        // $SymbolName = '#PCDATA';
        // $BvarQ = '(bvar)*';
        // $DomainQ = '(domainofapplication|condition|(lowlimit,uplimit?))*'
        // $constantArithMmlclass = array('exponentiale', 'imaginaryi', 'notanumber', 'true', 'false', 'pi', 'eulergamma', 'infinity');
        // $constantSetMmlclass = array('integers', 'reals', 'rationals', 'naturalnumbers', 'complexes', 'primes', 'emptyset');
        // $binaryLinalgMmlclass = array('vectorproduct', 'scalarproduct', 'outerproduct');
        // $naryLinalgMmlclass = array('selector');
        // $unaryLinalgMmlclass = array('determinant', 'transpose');
        // $naryConstructorMmlclass = array('vector', 'matrix', 'matrixrow');
        // $naryStatsMmlclass = array('mean', 'sdev', 'variance', 'median', 'mode');
        // $unaryElementaryMmlclass = array('sin', 'cos', 'tan', 'sec', 'csc', 'cot', 'sinh', 'cosh', 'tanh', 'sech', 'csch', 'coth', 'arcsin', 'arccos', 'arctan', 'arccosh', 'arccot', 'arccoth', 'arccsc', 'arccsch', 'arcsec', 'arcsech', 'arcsinh', 'arctanh');
        // $limitMmlclass = array('limit');
        // $productMmlclass = array('product');
        // $sumMmlclass = array('sum');
        // $unarySetMmlclass = array('card');
        // $narySetRelnMmlclass = array('subset', 'prsubset');
        // $binarySetMmlclass = array('in', 'notin', 'notsubset', 'notprsubset', 'setdiff');
        // $narySetMmlclass = array('union', 'intersect', 'cartesianproduct');
        // $narySetlistConstructorMmlclass = array('set', 'list');
        // $unaryVeccalcMmlclass = array('divergence', 'grad', 'curl', 'laplacian');
        // $partialdiffMmlclass = array('partialdiff');
        // $DifferentialOperatorMmlclass = array('diff');
        // $intMmlclass = array('int');
        // $binaryRelnMmlclass = array('neq', 'approx', 'factorof', 'tendsto');
        // $naryRelnMmlclass = array('eq', 'gt', 'lt', 'geq', 'leq');
        // $quantifierMmlclass = array('forall', 'exists');
        // $binaryLogicalMmlclass = array('implies', 'equivalent');
        // $unaryLogicalMmlclass = array('not');
        // $naryLogicalMmlclass = array('and', 'or', 'xor');
        // $naryArithMmlclass = array('plus', 'times', 'gcd', 'lcm');
        // $naryMinmaxMmlclass = array('max', 'min');
        // $unaryArithMmlclass = array('factorial', 'abs', 'conjugate', 'arg', 'real', 'imaginary', 'floor', 'ceiling', 'exp');
        //        $binaryArithMmlclass = array('quotient', 'divide', 'minus', 'power', 'rem', 'root');
        //        $naryFunctionalMmlclass = array('compose');
        //        $lambdaMmlclass = array('lambda');
        //        $unaryFunctionalMmlclass = array('inverse', 'ident', 'domain', 'codomain', 'image', 'ln', 'log', 'moment');
        //        $intervalMmlclass = array('interval');
        //        $DeprecatedContExp = array('reln', 'fn', 'declare');
        //        $CommonDeprecatedAtt = array('other' => 'CDATA');
        //        $Qualifier = $DomainQ + array('degree', 'momentabout', 'logbase');
        //        $ContExp = array('piecewise') + $DeprecatedContExp + $IntervalMmlclass + $unaryFunctionalMmlclass + $lambdaMmlclass + $naryFunctionalMmlclass + $binaryArithMmlclass + $unaryArithMmlclass + $naryMinmaxMmlclass + $naryArithMmlclass + $naryLogicalMmlclass + $unaryLogicalMmlclass + $binaryLogicalMmlclass + $quantifierMmlclass + $naryRelnMmlclass + $binaryRelnMmlclass + $intMmlclass + $DifferentialOperatorMmlclass + $partialDiffMmlclass + $unaryVeccalcMmlclass + $narySetlistConstructorMmlclass + $narySetMmlclass + $binarySetMmlclass + $narySetRelnMmlclass + $unarySetMmlclass + $sumMmlclass + $productMmlclass + $limitMmlclass + $unaryElementaryMmlclass + $naryStatsMmlclass + $naryConstructorMmlclass + $unaryLinalgMmlclass + $naryLinalgMmlclass + $binaryLinalgMmlclass + $constantSetMmlclass + $constantArithMmlclass + array('semantics', 'cn', 'ci', 'csymbol', 'apply', 'bind', 'share', 'cerror', 'cbytes', 'cs');
        //        $CommonAtt = array(
        //            '%XLINK/prefix;:href' => 'CDATA',
        //            '%XLINK.prefix;:type' => 'CDATA',
        //            'xml:lang' => 'CDATA',
        //            'xml:space' => 'Enum#default|preserve',
        //            'id' => $MMLIDTYPE,
        //            'xref' => $MMLIDREFTYPE,
        //            'class' => 'CDATA',
        //            'style' => 'CDATA',
        //            'href' => 'CDATA'
        //        ) + $CommonDeprecatedAtt;
        //        $applyContent = '(' + implode('|', $ContExp) + '),(' + implode('|', $BvarQ) + '),(' + implode('|', $Qualifier) + ')*,(' + implode('|', $ContExp) + ')*';
        //        $bindContent = $applyContent;
        //        $src = array('src' => 'CDATA');
        //        $base64 = 'CDATA';
        //        $DefEncAtt = array('encoding' => 'CDATA', 'definitionURL' => 'CDATA');
        //        $ciType = array('type*' => 'CDATA');
        //        $base = array('base*' => 'CDATA');
        //        $type = array('type*' => 'CDATA');
        //        $order = array('order*' => 'Enum#numeric|lexicographic');
        //        $closure = array('closure*' => 'CDATA');
        //        $MathExpression = $ContExp + $PresentationExpression;
        //        $ImpliedMrow = '(' + implode('|', $MathExpression) + ')*';
        //        $TableRowExpression = array('mtr', 'mlabeledtr');
        //        $MstackExpression = $MathExpression + array('mscarries', 'msline', 'msrow', 'msgroup');
        //        $MsrowExpression = $MathExpression + array('none');
        //        $MultiScriptExpression = '(' + implode('|', $MathExpression) + '| none),(' + implode('|', $MathExpression) + '|none');
        //        $mpaddedLength = 'CDATA';
        //        $linestyle = array('left', 'center', 'right');
        //        $notationstyle = array('longdiv', 'actuarial', 'radical', 'box', 'roundedbox', 'circle', 'left', 'right', 'top', 'bottom' 'updiagonalstrike', 'downdiagonalstrike', 'verticalstrike', 'horizontalstrike', 'madruwb');
        //        $idref = '#PCDATA';
        //        $unsignedInteger = 'CDATA';
        //        $integer = 'CDATA';
        //        $number = 'CDATA';
        //        $character = 'CDATA';
        //        $color = 'CDATA';
        //        $groupAlignment = array('left', 'center', 'right', 'decimalpoint');
        //        $groupAlignmentList = '#PCDATA';
        //        $groupAlignmentListList = '#PCDATA';
        //        $positiveInteger = 'CDATA';
        //        $tokenContent = array('#PCDATA', 'mglyph', 'malignmark');
        //        $length = 'CDATA';
        //        $DeprecatedTokenAtt = array(
        //            'fontfamily' => 'CDATA',
        //            'fontweight' => 'Enum#normal|bold',
        //            'fontstyle' => 'Enum#normal|italic',
        //            'fontsize' => 'Length',
        //            'color' => 'Color',
        //            'background' => 'CDATA'
        //        );
        //        $TokenAtt = array(
        //            'mathvariant' => 'Enum#normal|bold|italic|bold-italic|double-struck|bold-fraktur|script|bold-script|fraktur|sans-serif|bold-sans-serif|sans-serif-italic|sans-serif-bold-italic|monospace|initial|tailed|looped|stretched',
        //            'mathsize' => 'CDATA',
        //            'dir' => 'Enum#ltr|rtl'
        //        ) + $DeprecatedTokenAtt;
        //        $CommonPresAtt = array(
        //            'mathcolor' => 'Color',
        //            'mathbackground' => 'CDATA'
        //        );
        //        $mglyphDeprecatedAttributes = array(
        //            'index' => 'Number',
        //            'mathvariant' => 'Enum#normal|bold|italic|bold-italic|double-struck|bold-fraktur|script|bold-script|fraktur|sans-serif|bold-sans-serif|sans-serif-italic|sans-serif-bold-italic|monospace|initial|tailed|looped|stretched',
        //            'mathsize' => 'CDATA'
        //        ) + $DeprecatedTokenAtt;
        //        $mglyphAttributes = $CommonAtt + $CommonPresAtt + array(
        //            'src' => 'CDATA',
        //            'width' => 'Length',
        //            'height' => 'Length',
        //            'valign' => 'Length',
        //            'alt' => 'CDATA'
        //        );
        //        $mstyleDeprecatedAttributes = $DeprecatedTokenAtt + array(
        //            'veryverythinmathspace' => 'Length',
        //            'verythinmathspace' => 'Length',
        //            'thinmathspace' => 'Length',
        //            'mediummathspace' => 'Length',
        //            'thickmathspace' => 'Length',
        //            'verythickmathspace' => 'Length',
        //            'veryverythickmathspace' => 'Length'
        //        );
        //        $mstyleGeneralAttributes = array(
        //            'accent' => 'Enum#true|false',
        //            'accentunder' => 'Enum#true|false',
        //            'align' => 'Enum#left|right|center',
        //            'alignmentscope' => 'CDATA',
        //            'bevelled' => 'Enum#true|false',
        //            'charalign' => 'Enum#left|center|right',
        //            'charspacing' => 'CDATA',
        //            'close' => 'CDATA',
        //            'columnalign' => 'CDATA',
        //            'columnlines' => 'CDATA',
        //            'columnspacing' => 'CDATA',
        //            'columnspan' => 'Number',
        //            'columnwdith' => 'CDATA',
        //            'crossout' => 'CDATA',
        //            'denomalign' => 'Enum#left|center|right',
        //            'depth' => 'Length',
        //            'dir' => 'Enum#ltr|rtl',
        //            'edge' => 'Enum#left|right',
        //            'equalcolumns' => 'Enum#true|false',
        //            'equalrows' => 'Enum#true|false',
        //            'fence' => 'Enum#true|false',
        //            'form' => 'Enum#true|false',
        //            'frame' => 'Enum#' + implode('|', $linestyle),
        //            'framespacing' => 'CDATA',
        //            'groupalign' => 'CDATA',
        //            'height' => 'Length',
        //            'indentalign' => 'Enum#left|center|right|auto|id',
        //            'indentalignfirst' => 'Enum#left|center|right|auto|id|indentalign',
        //            'indentalignlast' => 'Enum#left|center|right|auto|id|indentalign',
        //            'indentshift' => 'Length',
        //            'indentshiftfirst' => 'CDATA',
        //            'indentshiftlast' => 'CDATA',
        //            'indenttarget' => 'CDATA',
        //            'largeop' => 'Enum#true|false',
        //            'leftoverhang' => 'Length',
        //            'length' => 'Number',
        //            'linebreak' => 'Enum#auto|newline|nobreak|goodbreak|badbreak',
        //            'linebreakmultchar' => 'CDATA',
        //            'linebreakstyle' => 'Enum#before|after|duplicate|infixlinebreakstyle',
        //            'lineleading' => 'Length',
        //            'linethickness' => 'CDATA',
        //            'location' => 'Enum#w|nw|n|ne|e|se|s|sw',
        //            'longdivstyle' => 'CDATA',
        //            'lquote' => 'CDATA',
        //            'lspace' => 'Length',
        //            'mathsize' => 'CDATA',
        //            'mathvariant' => 'Enum#normal|bold|italic|bold-italic|double-struck|bold-fraktur|script|bold-script|fraktur|sans-serif|bold-sans-serif|sans-serif-italic|sans-serif-bold-italic|monospace|initial|tailed|looped|stretched',
        //            'maxsize' => 'CDATA',
        //            'minlabelspacing' => 'Length',
        //            'minsize' => 'Length',
        //            'movablelimits' => 'Enum#true|false',
        //            'mslinethickness' => 'CDATA',
        //            'notation' => 'CDATA',
        //            'numalign' => 'Enum#left|center|right',
        //            'open' => 'CDATA',
        //            'position' => 'Number',
        //            'rightoverhang' => 'Length',
        //            'rowalign' => 'CDATA',
        //            'rowlines' => 'CDATA',
        //            'rowspacing' => 'CDATA',
        //            'rowspan' => 'Number',
        //            'rquote' => 'CDATA',
        //            'rspace' => 'Length',
        //            'selection' => 'Number',
        //            'separator' => 'Enum#true|false',
        //            'separators' => 'CDATA',
        //            'shift' => 'Number',
        //            'side' => 'Enum#left|right|leftoverlap|rightoverlap',
        //            'stackalign' => 'Enum#left|center|right|decimalpoint',
        //            'stretchy' => 'Enum#true|false',
        //            'subscriptshift' => 'Length',
        //            'superscriptshift' => 'Length',
        //            'symmetric' => 'Enum#true|false',
        //            'valign' => 'Length',
        //            'width' => 'Length'
        //        );
        //        $mstyleSpecificAttributes = array(
        //            'scriptlevel' => 'Number',
        //            'displaystyle' => 'Enum#true|false',
        //            'scriptsizemultiplier' => 'Number',
        //            'scriptminsize' => 'Length',
        //            'infixlinebreakstyle' => 'Enum#before|after|duplicate',
        //            'decimalpoint' => 'Character'
        //        );
        //        $msubsupAttributes = $CommonAtt + $CommonPresAtt + array(
        //            'subscriptshift' => 'Length',
        //            'superscriptshift' => 'Length'
        //        );
        //        $mtrAttributes = $CommonAtt + $CommonPresAtt + array(
        //            'rowalign' => 'Enum#top|bottom|center|baseline|axis',
        //            'columnalign' => 'CDATA',
        //            'groupalign' => 'CDATA'
        //        );
        //        $msgroupAttributes = $CommonAtt + $CommonPresAtt + array(
        //            'position' => 'Number',
        //            'shift' => 'Number'
        //        );
        //        $NonMathMLAtt = array();
        //        $mathDeprecatedAttributes = array(
        //            'mode' => 'CDATA',
        //            'macros' => 'CDATA'
        //        );
        //        $name = array('name*' => 'CDATA');
        //        $cd = array('cd*' => 'CDATA');
        //        $annotationAttributes = $CommonAtt + array(
        //            'cd' => 'CDATA',
        //            'name' => 'CDATA'
        //        ) + $DefEncAtt + array('src' => 'CDATA');
        //        $annotationXmlModel = '(' + implode('|', $MathExpression) + ')*';
        //        $anyElement = '';
        // allow 'data-table-sort' attribute to indicate that a table shall be sortable by js
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addAttribute('table', 'data-table-sort', 'Enum#true');
            $def->addAttribute('td', 'headers', 'NMTOKENS');
            $def->addAttribute('th', 'headers', 'NMTOKENS');
            /**
             * MathML elements
             * for adding custom elements: http://htmlpurifier.org/docs/enduser-customize.html
             * examples of custom elements: https://repo.or.cz/w/htmlpurifier.git?a=tree;hb=HEAD;f=library/HTMLPurifier/HTMLModule
             * MathML reference page: https://www.w3.org/Math/DTD/mathml3/mathml3.dtd
             */
            // cn
            // ci
            // csymbol
            // apply
            // bind
            // share
            // cerror
            // cbytes
            // cs
            // bvar
            // sep
            // domainofapplication
            // condition
            // uplimit
            // lowlimit
            // degree
            // momentabout
            // logbase
            // piecewise
            // piece
            // otherwise
            // reln
            // fn
            // declare
            // interval
            // inverse
            // ident
            // domain
            // codomain
            //            image
            //            ln
            //            log
            //            moment
            //            lambda
            //            compose
            //            quotient
            //            divide
            //            minus
            //            power
            //            rem
            //            root
            //            factorial
            //            abs
            //            conjugate
            //            arg
            //            real
            //            imaginary
            //            floor
            //            ceiling
            //            exp
            //            max
            //            min
            //            times
            //            gcd
            //            lcm
            //            and
            //            or
            //            xor
            //            not
            //            implies
            //            equivalent
            //            forall
            //            exists
            //            eq
            //            gt
            //            lt
            //            geq
            //            leq
            //            neq
            //            approx
            //            factorof
            //            tendsto
            //            int
            //            diff
            //            partialdiff
            //            divergence
            //            grad
            //            curl
            //            laplacian
            //            set
            //            list
            //            union
            //            intersect
            //            cartesianproduct
            //            in
            //            notin
            //            notsubset
            //            notprsubset
            //            setdiff
            //            subset
            //            prsubset
            //            card
            //            sum
            //            product
            //            limit
            //            sin
            //            cos
            //            tan
            //            sec
            //            csc
            //            cot
            //            sinh
            //            cosh
            //            tanh
            //            sech
            //            csch
            //            coth
            //            arcsin
            //            arccos
            //            arctan
            //            arccosh
            //            arccot
            //            arccoth
            //            arccsc
            //            arccsch
            //            arcsec
            //            arcsech
            //            arcsinh
            //            arctanh
            //            mean
            //            sdev
            //            variance
            //            median
            //            mode
            //            vector
            //            matrix
            //            matrixrow
            //            determinant
            //            transpose
            //            selector
            //            vectorproduct
            //            scalarproduct
            //            outerproduct
            //            integers
            //            reals
            //            rationals
            //            naturalnumbers
            //            complexes
            //            primes
            //            emptyset
            //            exponentiale
            //            imaginaryi
            //            notanumber
            //            true
            //            false
            //            pi
            //            eulergamma
            //            infinity
            //            mi
            //            mn
            //            mo
            //            mtext
            //            mspace
            //            ms
            //            mglyph
            //            msline
            //            none
            //            mprescripts
            //            malignmark
            //            maligngroup
            //            mrow
            //            mfrac
            //            msqrt
            //            mroot
            //            mstyle
            //            merror
            //            mpadded
            //            mphantom
            //            mfenced
            //            menclosed
            //            msub
            //            msup
            //            msubsup
            //            munder
            //            mover
            //            munderover
            //            mmultiscripts
            //            mtable
            //            mlabeledtr
            //            mtr
            //            mtd
            //            mstack
            //            mlongdiv
            //            msgroup
            //            msrow
            //            mscarries
            //            mscarry
            //            maction
            //            $def->addElement(
            //                'math',
            //                false,
            //                '(' + implode('|', $MathExpression) + ')*',
            //                null,
            //                $CommonAtt + array(
            //                    'display' => 'Enum#block|inline',
            //                    'maxwidth' => 'Length',
            //                    'overflow' => 'Enum#linebreak|scroll|elide|truncate|scale',
            //                    'altimg' => 'CDATA',
            //                    'altimg-width' => 'Length',
            //                    'altimg-height' => 'Length',
            //                    'altimg-valign' => 'CDATA',
            //                    'alttext' => 'CDATA',
            //                    'cdgroup' => 'CDATA'
            //                ) + $mathDeprecatedAttributes + $CommonPresAtt + $mstyleSpecificAttributes + $mstyleGeneralAttributes
            //            );
            //            $def->addElement(
            //              'annotation',
            //              'Inline',
            //              'Empty',
            //              null,
            //              $annotationAttributes
            //            );
            //            annotation-xml
            //            semantics
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
