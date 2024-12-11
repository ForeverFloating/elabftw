const htmlfile = require('fs').readFileSync(process.argv[2], 'utf8');
const cheerio = require('cheerio');
const { expressionRegex, idRegex, arrayRegex, regexSelector, mathOutput } = require('../ts/mathjs');

const $ = cheerio.load(htmlfile);

function mathExpression(inputString) {
  const expressionString = inputString.replace(regexSelector, (selectMatch, expressionSelect) => {
    const selectArray = $('body').find(`${expressionSelect}`);
    const regexMatch = arrayRegex.test(selectMatch);
    if (regexMatch) {
      return selectArray.map((i, el) => $(el).text().trim()).get().join(', ');
    } else {
      return `${selectArray.length}`;
    }
  });
  return expressionString.replace(idRegex, (idMatch, expressionId) => {
    const element = $('body').find(`#${expressionId}`);
    return element.first().text();
  });
}

const result = htmlfile.replace(expressionRegex, (match, expression) => {
  const replaceRefs = mathExpression(expression);
  return mathOutput(match, replaceRefs);
});

console.log(result);
