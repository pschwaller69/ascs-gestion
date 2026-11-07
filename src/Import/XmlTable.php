<?php
declare(strict_types=1);

namespace Ascs\Import;

use DOMDocument;
use DOMElement;
use RuntimeException;
use XMLReader;

/** Lecture en flux d'un export XML WinDev/HFSQL (<WINDEV_TABLE><TABLE_CONTENU>…). */
final class XmlTable
{
    /** @return \Generator<int,array<string,string>> une ligne = tag => texte */
    public static function rows(string $path): \Generator
    {
        if (!is_file($path)) {
            throw new RuntimeException("Fichier introuvable : $path");
        }
        $reader = new XMLReader();
        // LIBXML_NONET : jamais d'accès réseau ; les entités externes ne sont pas résolues.
        if (!$reader->open($path, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException("XML illisible : $path");
        }
        $doc = new DOMDocument();
        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'TABLE_CONTENU') {
                    $node = $reader->expand($doc);
                    $row = [];
                    foreach ($node->childNodes as $child) {
                        if ($child instanceof DOMElement) {
                            $row[$child->tagName] = $child->textContent;
                        }
                    }
                    yield $row;
                    $reader->next();
                }
            }
        } finally {
            $reader->close();
        }
    }
}
