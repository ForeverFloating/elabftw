import { all, create } from 'mathjs';
const math = create(all);

// add support for Greek and Unicode letters
const isAlphaOriginal = math.Unit.isValidAlpha;
const isCustomChar = (c: string): boolean => {
  const charCode = c.charCodeAt(0);
  return (charCode >= 913 && charCode <= 969) || charCode == 8211 || charCode == 60 || charCode == 62 || charCode == 176 || charCode == 181 || charCode == 197 || charCode == 47;
};
math.Unit.isValidAlpha = function(c:string): boolean {
  return isAlphaOriginal(c) || isCustomChar(c);
};

//correct mu prefix
function addMu(prefixObj) {
  for (const prefixKey in prefixObj) {
    if (prefixObj[prefixKey] && typeof prefixObj[prefixKey] === 'object') {
      if (prefixObj[prefixKey].name === 'u') {
        const muObj = {...prefixObj[prefixKey], name: '\u03BC'};
        prefixObj['\u03BC'] = muObj;
      }
      addMu(prefixObj[prefixKey]);
    }
  }
}

addMu(math.Unit.PREFIXES);

math.createUnit({
// formatting existing short unit definitions
//  'm<sup>2</sup>': {
//    definition: '1 m2',
//    prefixes: 'short',
//  },
//  'in<sup>2</sup>': {
//    definition: '1 sqin',
//  },
//  'ft<sup>2</sup>': {
//    definition: '1 sqft',
//  },
//  'yd<sup>2</sup>': {
//    definition: '1 sqyd',
//  },
//  'mi<sup>2</sup>': {
//    definition: '1 sqmi',
//  },
//  'rd<sup>2</sup>': {
//    definition: '1 sqrd',
//  },
//  'ch<sup>2</sup>': {
//    definition: '1 sqch',
//  },
//  'mil<sup>2</sup>': {
//    definition: '1 sqmil',
//  },
//  'm<sup>3</sup>': {
//    definition: '1 m3',
//    prefixes: 'short',
//  },
//  'in<sup>3</sup>': {
//    definition: '1 cuin',
//  },
//  'ft<sup>3</sup>': {
//    definition: '1 cuft',
//  },
//  'yd<sup>3</sup>': {
//    definition: '1 cuyd',
//  },
  '\u00B0': {
    definition: '1 deg',
  },
  '\u00B0C': {
    definition: '1 degC',
  },
  '\u00B0F': {
    definition: '1 degF',
  },
  '\u00B0R': {
    definition: '1 degR',
  },
  'mmH<sub>2</sub>O': {
    definition: '1 mmH2O',
  },
  'cmH<sub>2</sub>O': {
    definition: '1 cmH2O',
  },
  //missing abbreviations
  'tsp': {
    definition: '1 teaspoon',
  },
  'tbsp': {
    definition: '1 tablespoon',
  },
  'd': {
    definition: '1 day',
  },
  'yr': {
    definition: '1 year',
  },
  '\u00C5': {
    definition: '1 angstrom',
  },
  '\u03B8': {
    definition: '1 rad',
  },
  '\u03A9': {
    definition: '1 ohm',
    prefixes: 'short',
  },
  //custom units
  'Da': {
    definition: `${math.evaluate('atomicMass')}`,
    prefixes: 'short',
    aliases: ['Daltons', 'Dalton'],
  },
  'kat': {
    definition: `${math.divide(math.unit('1 mol'), math.unit('1 s'))}`,
    prefixes: 'short',
    aliases: ['katal', 'katals'],
  },
  'M': {
    definition: `${math.divide(math.unit('1 mol'), math.unit('1 L'))}`,
    prefixes: 'short',
    aliases: ['molar', 'molars'],
  },
  'U': {
    definition: `${math.divide(math.unit('1 umol'), math.unit('1 min'))}`,
  },
},
{
  override: true,
});

export const expressionRegex = /{{\s*(.*?)\s*}}/g;
export const idRegex = /#([a-zA-z][a-zA-z\d-_.]*)/g;
export const arrayRegex = /\[?'([^\n'[\]]*?)'\]?/g;
export const regexSelector = /(?<!\[')\[(?:'([^\n']*)'|([^\n'[\]]*))\](?!]')/g;
export function mathOutput(mathMatch: string, mathExpression: string): string {
  try {
    const mathResult = math.evaluate(mathExpression);
    if (math.typeOf(mathResult) === 'Unit') {
      mathResult.units.forEach((units) => {
        const mathUnit = units.unit.name;
        if (units.prefix.name === 'u') {
          units.prefix.name = '\u03BC';
        }
        switch (mathUnit) {
        case 'deg':
          units.unit.name = '\u00B0';
          break;
        case 'degC':
          units.unit.name = '\u00B0C';
          break;
        case 'degF':
          units.unit.name = '\u00B0F';
          break;
        case 'degR':
          units.unit.name = '\u00B0R';
          break;
        case 'sqch':
          units.unit.name = 'ch<sup>2</sup>';
          break;
        case 'sqft':
          units.unit.name = 'ft<sup>2</sup>';
          break;
        case 'cuft':
          units.unit.name = 'ft<sup>3</sup>';
          break;
        case 'sqin':
          units.unit.name = 'in<sup>2</sup>';
          break;
        case 'cuin':
          units.unit.name = 'in<sup>3</sup>';
          break;
        case 'm2':
          units.unit.name = 'm<sup>2</sup>';
          break;
        case 'm3':
          units.unit.name = 'm<sup>3</sup>';
          break;
        case 'sqmil':
          units.unit.name = 'mil<sup>2</sup>';
          break;
        case 'sqrd':
          units.unit.name = 'rd<sup>2</sup>';
          break;
        case 'sqmi':
          units.unit.name = 'mi<sup>2</sup>';
          break;
        case 'sqyd':
          units.unit.name = 'yd<sup>2</sup>';
          break;
        case 'cuyd':
          units.unit.name = 'yd<sup>3</sup>';
          break;
        case 'mmH2O':
          units.unit.name = 'mmH<sub>2</sub>O';
          break;
        case 'cmH2O':
          units.unit.name = 'cmH<sub>2</sub>O';
          break;
        }
      });
    }
    return `${mathResult}`;
  } catch (err) {
    console.log(mathExpression);
    console.error('Math.js evaluation error:', err);
    return mathMatch;
  }
}

function calcExpression(inputString: string, nodeObj: HTMLElement): string {
  return inputString.replace(regexSelector, (selectMatch, expressionSelect1, expressionSelect2) => {
    if (expressionSelect1 === undefined) {
      expressionSelect1 = '';
    }
    if (expressionSelect2 === undefined) {
      expressionSelect2 = '';
    }
    const selectArray = nodeObj.querySelectorAll(expressionSelect1 + expressionSelect2);
    const regexMatch = arrayRegex.test(`${selectMatch}`);
    if (regexMatch) {
      return Array.from(selectArray).map(arrayNode => arrayNode.textContent.trim()).join(', ');
    } else {
      return `${selectArray.length}`;
    }
  });
}

function calcId(inputString: string, nodeObj: HTMLElement): string {
  return inputString.replace(idRegex, (idMatch, expressionId) => {
    const element = nodeObj.querySelector(`#${expressionId}`);
    return element.textContent.trim();
  });
}

function mathParse(inputString: string, nodeObj: HTMLElement): string {
  return inputString.replace(expressionRegex, (match, expression) => {
    const evaluatedExpression = calcExpression(expression, nodeObj);
    let evaluatedId = calcId(evaluatedExpression, nodeObj);
    if (expressionRegex.test(evaluatedId)) {
      evaluatedId = mathParse(evaluatedId, nodeObj);
    }
    return mathOutput(match, evaluatedId);
  });
}

export function mathString(inputString: string): string {
  const htmlString = inputString.replace(/^(<[\s\S]+><body(?: [^/!<>]+)?>|)((?:<\/?[^/!<>]+>[\s\S]*)*)(?:<\/body><\/html>|\1)/g, '<!DOCTYPE html><html><head></head><body>$2</body></html>');
  const parser = new DOMParser();
  const inputDOM = parser.parseFromString(htmlString, 'text/html').body;
  return mathParse(inputString, inputDOM);
}

export function mathDOM(rootNode: HTMLElement) {
  const nodeIterator = document.createNodeIterator(rootNode, NodeFilter.SHOW_TEXT, {
    acceptNode(node) {
      return expressionRegex.test(node.textContent)
        ? NodeFilter.FILTER_ACCEPT
        : NodeFilter.FILTER_REJECT;
    },
  });
  let currentNode;
  while ((currentNode = nodeIterator.nextNode())) {
    const nodeText = currentNode.textContent;
    const nodeParse = mathParse(nodeText, rootNode);
    const htmlTag = /<([a-z]+)>[\s\S]*<\/\1>|<w?br>/g;
    if (htmlTag.test(nodeParse)) {
      currentNode.parentNode?.replaceChild(
        document.createRange().createContextualFragment(nodeParse),
        currentNode,
      );
    } else {
      currentNode.textContent = nodeParse;
    }
  }
}
