<?php

namespace Assistant\Module\File\Extension;

/**
 * Rozszerzenie \SplFileInfo wspierające relatywne ścieżki do plików
 */
class SplFileInfo extends \SplFileInfo
{
    /**
     * Relatywna, w stosunku do katalogu głównego, ścieżka do elementu kolekcji
     *
     * @var string
     */
    private $relativePathname;

    /**
     * Określa, czy element jest ignorowany
     *
     * @var bool
     */
    private $ignored;

    /**
     * Określa, czy element jest kropką
     *
     * @var bool
     */
    private $dot;

    /**
     * Konstruktor
     *
     * @param string $filename
     * @param string $relativePathname
     * @param bool $isDot
     */
    public function __construct($filename, $relativePathname)
    {
        parent::__construct($filename);

        $this->relativePathname = sprintf('/%s', $relativePathname);

        $this->ignored = false;
        $this->dot = false;
    }

    /**
     * Ustawia flagę ignorowania dla elementu
     *
     * @param bool $ignored
     * @return self
     */
    public function setAsIgnored($ignored)
    {
        $this->ignored = (bool) $ignored;

        return $this;
    }

    /**
     * Zwraca informację, czy element jest ignorowany
     *
     * @return bool
     */
    public function isIgnored()
    {
        return $this->ignored;
    }

    /**
     * Ustawia flagę oznaczającą, że element jest kropką
     *
     * @param bool $dot
     * @return self
     */
    public function setAsDot($dot)
    {
        $this->dot = (bool) $dot;

        return $this;
    }


    /**
     * Ustawia flagę oznaczającą, że element jest kropką
     *
     * @return self
     */
    public function isDot()
    {
        return $this->dot;
    }

    /**
     * Zwraca relatywną, w stosunku do katalogu głównego, ścieżkę do elementu kolekcji
     *
     * @return string
     */
    public function getRelativePathname()
    {
        return $this->relativePathname;
    }
}
