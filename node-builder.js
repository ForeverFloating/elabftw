/**
 * @author Marcel Bolten
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 *
 * Config file for building node apps with webpack
 *
 */
const path = require('path');

module.exports = (env) => {
  return {
    target: 'node',
    entry: {
      tex2svg: [
        './src/node/tex2svg.js',
      ],
      evalmathjs: [
        './src/ts/mathjs.ts',
        './src/node/evalmathjs.js',
      ],
    },
    mode: 'production',
    output: {
      filename: '[name].bundle.js',
      path: path.resolve(__dirname, 'src/node'),
    },
    resolve: {
      extensions: ['.ts', '.js',],
    },
    module: {
      rules: [
        {
          test: /\.ts$/,
          use: {
            loader: 'ts-loader',
            options: {
              transpileOnly: env.production,
            },
          },
          exclude: /node_modules/,
        },
      ],
    },
  }
};
