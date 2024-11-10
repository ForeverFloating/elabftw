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

export function mathJs(inputString: string): string {
  const htmlString = inputString.replace(/^(<[\s\S]+><body(?: [^\/!<>]+)?>|)((?:<\/?[^\/!<>]+>[\s\S]*)*)(?:<\/body><\/html>|\1)/g, '<!DOCTYPE html><html><head></head><body>$2</body></html>');
  const parser = new DOMParser();
  const inputDOM = parser.parseFromString(htmlString, 'text/html');
  return inputString.replace(/{{\s*(.*?)\s*}}/g, (match, expression) => {
    const evaluatedId = expression.replace(/#(\w+)/g, (idMatch, expressionId) => {
      const element = inputDOM.querySelector(`#${expressionId}`);
      return element.textContent;
    });
    const evaluatedExpression = evaluatedId.replace(/\[?'([^\n'\[\]]*?)'\]?/g, (selectMatch, expressionSelect) => {
      const selectArray = inputDOM.querySelectorAll(`${expressionSelect}`);
      const regexMatch = /\[[^\n\[\]]*\]/g.test(selectMatch);
      if (regexMatch) {
        return Array.from(selectArray).map(arrayDOM => arrayDOM.textContent.trim()).join(', ');
      } else {
        return selectArray.length;
      }
    });
    try {
      const mathResult = math.evaluate(evaluatedExpression);
      return `<span class="math-expression">${mathResult}</span>`;
    } catch (err) {
      console.error('Math.js evaluation error:', err);
      return match;
    }
  });
}
