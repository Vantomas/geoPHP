<?php

namespace Phayes\GeoPHP\Adapters;

use Phayes\GeoPHP\GeoPHP;
use Phayes\GeoPHP\Adapters\GeoAdapter;
use Phayes\GeoPHP\Geometry\Point;
use Phayes\GeoPHP\Geometry\Polygon;
use Phayes\GeoPHP\Geometry\LineString;
use Phayes\GeoPHP\Geometry\MultiPoint;
use Phayes\GeoPHP\Geometry\MultiPolygon;
use Phayes\GeoPHP\Geometry\MultiLineString;
use Phayes\GeoPHP\Geometry\Geometry;
use Phayes\GeoPHP\Geometry\GeometryCollection;
use Exception;
use DOMDocument;

class KML extends GeoAdapter
{
  private $namespace = false;
  private $nss = ''; // Name-space string. eg 'georss:'
  private $properties = ['name', 'description']; //what properties parse from child


  /**
   * Read KML string into geometry objects
   *
   * @param string $kml A KML string
   *
   * @return Geometry|GeometryCollection
   */
  public function read($kml)
  {
    return $this->geomFromText($kml);
  }

  /**
   * Serialize geometries into a KML string.
   *
   * @param Geometry $geometry
   *
   * @return string The KML string representation of the input geometries
   */
  public function write(Geometry $geometry, $namespace = false)
  {
    if ($namespace) {
      $this->namespace = $namespace;
      $this->nss = $namespace.':';
    }

    return $this->geometryToKML($geometry);
  }

  public function geomFromText($text)
  {
    // Change to lower-case and strip all CDATA
    $text = mb_strtolower($text, mb_detect_encoding($text));
    $text = preg_replace('/<!\[cdata\[(.*?)\]\]>/s','',$text);

    // Load into DOMDocument
    $xmlobj = new DOMDocument();
    @$xmlobj->loadXML($text);
    if ($xmlobj === false) {
      throw new Exception("Invalid KML: ". $text);
    }
    $this->xmlobj = $xmlobj;
    try {
      $geom = $this->geomFromXML();
    } catch(InvalidText $e) {
        throw new Exception("Cannot Read Geometry From KML: ". $text);
    } catch(Exception $e) {
        throw $e;
    }

    return $geom;
  }

  protected function geomFromXML()
  {
    $geometries = [];
    $geom_types = GeoPHP::geometryList();
    $placemark_elements = $this->xmlobj->getElementsByTagName('placemark');
    if ($placemark_elements->length) {
      foreach ($placemark_elements as $placemark) {
		$properties = [];
		
		foreach ($placemark->childNodes as $child) {
          // Node names are all the same, except for MultiGeometry, which maps to GeometryCollection
          $node_name = $child->nodeName == 'multigeometry' ? 'geometrycollection' : $child->nodeName;

		  //Parse properties for child
		  if(in_array($node_name, $this->properties)) {
			  $properties[$node_name] = $child->nodeValue;
		  }

		  if (array_key_exists($node_name, $geom_types)) {
            $function = 'parse'.$geom_types[$node_name];
			$geometry = $this->$function($child);

			//Add properties to geometry
			foreach($properties as $propkey => $propvalue) {
				$geometry->setProperty($propkey, $propvalue);
			}

			//Add geometry
            $geometries[] = $geometry;
          }
        }
      }
    }
    else {
      // The document does not have a placemark, try to create a valid geometry from the root element
      $node_name = $this->xmlobj->documentElement->nodeName == 'multigeometry' ? 'geometrycollection' : $this->xmlobj->documentElement->nodeName;
      if (array_key_exists($node_name, $geom_types)) {
        $function = 'parse'.$geom_types[$node_name];
        $geometries[] = $this->$function($this->xmlobj->documentElement);
      }
    }

    return GeoPHP::geometryReduce($geometries);
  }

  protected function childElements($xml, $nodename = '')
  {
    $children = [];
    if ($xml->childNodes) {
      foreach ($xml->childNodes as $child) {
        if ($child->nodeName == $nodename) {
          $children[] = $child;
        }
      }
    }

    return $children;
  }

  protected function parsePoint($xml)
  {
    $coordinates = $this->_extractCoordinates($xml);
    if (!empty($coordinates)) {
      return new Point($coordinates[0][0],$coordinates[0][1]);
    }
    else {
      return new Point();
    }
  }

  protected function parseLineString($xml)
  {
    $coordinates = $this->_extractCoordinates($xml);
    $point_array = array();
    foreach ($coordinates as $set) {
      $point_array[] = new Point($set[0],$set[1]);
    }

    return new LineString($point_array);
  }

  protected function parsePolygon($xml)
  {
    $components = [];
    $outer_boundary_element_a = $this->childElements($xml, 'outerboundaryis');

    if (empty($outer_boundary_element_a)) {
      return new Polygon(); // It's an empty polygon
    }

    $outer_boundary_element = $outer_boundary_element_a[0];
    $outer_ring_element_a = $this->childElements($outer_boundary_element, 'linearring');
    $outer_ring_element = $outer_ring_element_a[0];
    $components[] = $this->parseLineString($outer_ring_element);
    if (count($components) != 1) {
      throw new Exception("Invalid KML");
    }
    $inner_boundary_element_a = $this->childElements($xml, 'innerboundaryis');
    if (count($inner_boundary_element_a)) {
      foreach ($inner_boundary_element_a as $inner_boundary_element) {
        foreach ($this->childElements($inner_boundary_element, 'linearring') as $inner_ring_element) {
          $components[] = $this->parseLineString($inner_ring_element);
        }
      }
    }

    return new Polygon($components);
  }

  protected function parseGeometryCollection($xml)
  {
    $components = [];
    $geom_types = geoPHP::geometryList();

    foreach ($xml->childNodes as $child) {
      $nodeName = ($child->nodeName == 'linearring') ? 'linestring' : $child->nodeName;
      if (array_key_exists($nodeName, $geom_types)) {
        $function = 'parse'.$geom_types[$nodeName];
        $components[] = $this->$function($child);
      }
    }

    return new GeometryCollection($components);
  }

  protected function _extractCoordinates($xml)
  {
    $coord_elements = $this->childElements($xml, 'coordinates');
    $coordinates = [];

    if (count($coord_elements)) {
      $coord_sets = explode(' ', preg_replace('/[\r\n]+/', ' ', $coord_elements[0]->nodeValue));
      foreach ($coord_sets as $set_string) {
        $set_string = trim($set_string);
        if ($set_string) {
          $set_array = explode(',',$set_string);
          if (count($set_array) >= 2) {
            $coordinates[] = $set_array;
          }
        }
      }
    }

    return $coordinates;
  }

  private function geometryToKML($geom)
  {
    $type = strtolower($geom->getGeomType());

    switch ($type) {
      case 'point':
        return $this->pointToKML($geom);
        break;
      case 'linestring':
        return $this->linestringToKML($geom);
        break;
      case 'polygon':
        return $this->polygonToKML($geom);
        break;
      case 'multipoint':
      case 'multilinestring':
      case 'multipolygon':
      case 'geometrycollection':
        return $this->collectionToKML($geom);
        break;
    }
  }

  private function pointToKML($geom)
  {
    $out = '<'.$this->nss.'Point>';

    if (!$geom->isEmpty()) {
      $out .= '<'.$this->nss.'coordinates>'.$geom->getX().",".$geom->getY().'</'.$this->nss.'coordinates>';
    }

    $out .= '</'.$this->nss.'Point>';
    return $out;
  }


  private function linestringToKML($geom, $type = false)
  {
    if (!$type) {
      $type = $geom->getGeomType();
    }
    $str = '<'.$this->nss . $type .'>';
    if (!$geom->isEmpty()) {
      $str .= '<'.$this->nss.'coordinates>';
      $i=0;
      foreach ($geom->getComponents() as $comp) {
        if ($i != 0) $str .= ' ';
        $str .= $comp->getX() .','. $comp->getY();
        $i++;
      }
      $str .= '</'.$this->nss.'coordinates>';
    }
    $str .= '</'. $this->nss . $type .'>';

    return $str;
  }

  public function polygonToKML($geom)
  {
    $components = $geom->getComponents();
    $str = '';

    if (!empty($components)) {
      $str = '<'.$this->nss.'outerBoundaryIs>' . $this->linestringToKML($components[0], 'LinearRing') . '</'.$this->nss.'outerBoundaryIs>';
      foreach (array_slice($components, 1) as $comp) {
        $str .= '<'.$this->nss.'innerBoundaryIs>' . $this->linestringToKML($comp) . '</'.$this->nss.'innerBoundaryIs>';
      }
    }

    return '<'.$this->nss.'Polygon>'. $str .'</'.$this->nss.'Polygon>';
  }

  public function collectionToKML($geom)
  {
    $components = $geom->getComponents();
    $str = '<'.$this->nss.'MultiGeometry>';

    foreach ($geom->getComponents() as $comp) {
      $sub_adapter = new KML();
      $str .= $sub_adapter->write($comp);
    }

    return $str .'</'.$this->nss.'MultiGeometry>';
  }
}
