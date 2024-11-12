import { all, create } from 'mathjs';
const math = create(all);

// add support for Greek and Unicode letters
const isAlphaOriginal = math.Unit.isValidAlpha;
const isGreekLowercaseChar = (c: string): boolean => {
  const charCode = c.charCodeAt(0);
  return charCode >= 945 && charCode <= 969;
};
const isSymbolChar = (c: string): boolean => {
  const charCode = c.charCodeAt(0);
  return charCode >= 161 && charCode <= 191
};
const isUnicodeChar = (c: string): boolean => {
  const charCode = c.charCodeAt(0);
  return charCode == 8211
};
math.Unit.isValidAlpha = function (c:string): boolean {
  return isAlphaOriginal(c) || isGreekLowercaseChar(c);
};

//define custom units
math.createUnit({
  θ: {
    definition: '1 rad',
  },
  Da: {
    definition: '1.66053892173e-27 kg',
    prefixes: 'short',
    aliases: ['Daltons', 'Dalton'],
  },
},
{
  override: true,
})

const expressionRegex = /{{\s*(.*?)\s*}}/g;
const idRegex = /#(\w+)/g;
const arrayRegex = /\[?'([^\n'\[\]]*?)'\]?/g;
const regexSelector = /\[[^\n\[\]]*\]/g;
function mathOutput(mathMatch: string, mathExpression: string): string {
  try {
    const mathResult = math.evaluate(mathExpression);
    return `${mathResult}`;
  } catch (err) {
    console.error('Math.js evaluation error:', err);
    return mathMatch;
  }
}

function calcExpression(inputString: string, nodeObj: HTMLElement): string {
  return inputString.replace(regexSelector, (selectMatch, expressionSelect) => {
    const selectArray = nodeObj.querySelectorAll(`${expressionSelect}`);
    const regexMatch = arrayRegex.test(selectMatch);
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
    return element.textContent;
  });
}

function mathParse(inputString: string, nodeObj: HTMLElement): string {
  return inputString.replace(expressionRegex, (match, expression) => {
    const evaluatedId = calcId(expression, nodeObj);
    const evaluatedExpression = calcExpression(evaluatedId, nodeObj);
    return mathOutput(match, evaluatedExpression);
  });
}

export function mathString(inputString: string): string {
  const htmlString = inputString.replace(/^(<[\s\S]+><body(?: [^\/!<>]+)?>|)((?:<\/?[^\/!<>]+>[\s\S]*)*)(?:<\/body><\/html>|\1)/g, '<!DOCTYPE html><html><head></head><body>$2</body></html>');
  const parser = new DOMParser();
  const inputDOM = parser.parseFromString(htmlString, 'text/html').body;
  return mathParse(inputString, inputDOM);
}

export function mathDOM(bodyId: string) {
  const rootNode = document.getElementById(bodyId);
  const nodeIterator = document.createNodeIterator(rootNode, NodeFilter.SHOW_TEXT, {
    acceptNode(node) {
      return expressionRegex.test(node.textContent)
      ? NodeFilter.FILTER_ACCEPT
      : NodeFilter.FILTER_REJECT;
    }
  });
  let currentNode;
  while (currentNode = nodeIterator.nextNode()) {
    let nodeText = currentNode.textContent;
    currentNode.textContent = mathParse(nodeText, rootNode);
  };
}
