<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.ogimage
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Categories\Categories;

/**
 * Content Plugin: setzt og:image für com_content Artikel / Blog / Featured
 */
class PlgContentOgimage extends CMSPlugin
{
    /**
     * Event: wird nach der Inhaltsausgabe aufgerufen
     *
     * @param string $context
     * @param object $article
     * @param mixed  $params
     * @param int    $limitstart
     *
     * @return string Leerstring, da wir nichts an den Content anhängen
     */
    public function onContentAfterDisplay(string $context, &$article, &$params, int $limitstart = 0): string
    {
        $app = Factory::getApplication();

        // Nur im Frontend
        if (!$app->isClient('site')) {
            return '';
        }


        $input = $app->input;

        // Nur com_content
        if ($input->getCmd('option') !== 'com_content') {
            return '';
        }

        // Erlaubte Views: Einzelartikel, Kategorie-Blog, Hauptbeiträge/Featured
        $view         = $input->getCmd('view');
        $allowedViews = ['article', 'category', 'featured'];

        if (!in_array($view, $allowedViews, true)) {
            return '';
        }

        $doc = $app->getDocument();

        // Sicherstellen, dass wir nur EINMAL pro Request setzen,
        // auch wenn onContentAfterDisplay für mehrere Artikel aufgerufen wird
        static $ogImageSet = false;

        if ($ogImageSet) {
            return '';
        }

        // Wenn bereits ein og:image gesetzt ist (z.B. durch ein anderes Plugin), nichts machen
        $existingOg = $doc->getMetaData('og:image');
        if (!empty($existingOg)) {
            return '';
        }

        // ---------------------------------------------------------------------
        // 1) Plugin-Einstellung: Artikelbild verwenden, wenn vorhanden?
        // ---------------------------------------------------------------------
        $useArticleImage = (bool) $this->params->get('use_article_image', 1);

        // Das Bild, das später als Basis für og:image dient
        $rawOgImage = '';

        // ---------------------------------------------------------------------
        // 2) Kategorienbild bei Blog-/Featured-Ansichten bevorzugen
        // ---------------------------------------------------------------------
        if (in_array($view, ['category', 'featured'], true) && !empty($article->catid)) {
            $categories = Categories::getInstance('Content');
            $category   = $categories->get((int) $article->catid);

            if ($category) {
                $catParams = $category->getParams();
                $catImage  = (string) $catParams->get('image', '');

                if ($catImage !== '') {
                    $rawOgImage = $catImage;
                }
            }
        }

        // ---------------------------------------------------------------------
        // 3) Wenn kein Kategorienbild: Artikelbild / Default-Bild nach Option
        // ---------------------------------------------------------------------
        if ($rawOgImage === '') {
            if ($useArticleImage) {
                // ---------------------------------------------
                // Variante A: Artikelbild bevorzugen
                // ---------------------------------------------

                // 3a) Artikelbilder aus JSON (images)
                if (!empty($article->images)) {
                    $images = json_decode($article->images);

                    if ($images instanceof \stdClass) {
                        // Reihenfolge: zuerst Fulltext-Bild, dann Intro-Bild
                        if (!empty($images->image_fulltext)) {
                            $rawOgImage = $images->image_fulltext;
                        } elseif (!empty($images->image_intro)) {
                            $rawOgImage = $images->image_intro;
                        }
                    }
                }

                // 3b) Falls in manchen Kontexten direkt als Properties gesetzt
                if ($rawOgImage === '') {
                    if (!empty($article->image_fulltext)) {
                        $rawOgImage = $article->image_fulltext;
                    } elseif (!empty($article->image_intro)) {
                        $rawOgImage = $article->image_intro;
                    }
                }

                // 3c) Letzter Versuch: erstes <img> aus dem Artikeltext ziehen
                if ($rawOgImage === '' && !empty($article->text)) {
                    if (preg_match('#<img[^>]+src=["\']([^"\']+)["\']#i', $article->text, $m)) {
                        $rawOgImage = $m[1];
                    }
                }

                // 3d) Wenn immer noch nichts gefunden → auf Default-Bild zurückfallen
                if ($rawOgImage === '') {
                    $defaultImage = (string) $this->params->get('default_image', '');
                    if ($defaultImage !== '') {
                        $rawOgImage = $defaultImage;
                    } else {
                        // Weder Kategorie-, Artikel- noch Default-Bild → nichts setzen
                        return '';
                    }
                }
            } else {
                // ---------------------------------------------
                // Variante B: IMMER Default-Bild, egal ob Artikelbild existiert
                // ---------------------------------------------
                $defaultImage = (string) $this->params->get('default_image', '');
                if ($defaultImage !== '') {
                    $rawOgImage = $defaultImage;
                } else {
                    // Kein Default definiert → nichts setzen
                    return '';
                }
            }
        }

        // ---------------------------------------------------------------------
        // 4) Bild-URL bereinigen & in Pfad + Maße umwandeln
        // ---------------------------------------------------------------------

        // cleanImageURL entfernt #joomlaImage://... und width/height-Parameter
        $img = HTMLHelper::_('cleanImageURL', $rawOgImage);
        $url = $img->url;   // typischerweise "images/..." oder "/images/..."

        if ($url === '') {
            return '';
        }

        // Sicherstellen, dass der Pfad mit "/" beginnt
        if ($url[0] !== '/') {
            $url = '/' . $url;
        }

        // Breite und Höhe aus den Attributen holen (falls vorhanden)
        $width  = 0;
        $height = 0;

        if (!empty($img->attributes['width'])) {
            $width = (int) $img->attributes['width'];
        }

        if (!empty($img->attributes['height'])) {
            $height = (int) $img->attributes['height'];
        }

        // ---------------------------------------------------------------------
        // 5) og:image + og:image:width/height setzen
        // ---------------------------------------------------------------------

        $doc->setMetaData('og:image', $url, 'property');

        if ($width > 0) {
            $doc->setMetaData('og:image:width', (string) $width, 'property');
        }

        if ($height > 0) {
            $doc->setMetaData('og:image:height', (string) $height, 'property');
        }

        $ogImageSet = true;

        return '';
    }
}