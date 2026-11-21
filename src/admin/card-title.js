/**
 * A card title at the size of WordPress admin section titles.
 *
 * @param {Object}  props          Component props.
 * @param {Element} props.children Title.
 */
export default function CardTitle( { children } ) {
	return <h2 className="windrose-helm__card-title">{ children }</h2>;
}
