<?php

declare(strict_types=1);

namespace Drupflare\Rom\Sql;

/**
 * The lexical classes the MySQL lexer assigns.
 *
 * Keywords are not a class: a keyword is an Identifier token whose text the
 * translator compares, because MySQL lets most keywords name a column.
 */
enum TokenType: string
{
	case Whitespace = 'whitespace';
	case Comment = 'comment';
	case ExecutableComment = 'executable_comment';
	case Identifier = 'identifier';
	case QuotedIdentifier = 'quoted_identifier';
	case String = 'string';
	case NationalString = 'national_string';
	case Hex = 'hex';
	case Bit = 'bit';
	case Number = 'number';
	case Variable = 'variable';
	case Operator = 'operator';
	case Punct = 'punct';
	case Error = 'error';
}
