import type { PageLoad } from './$types';

// Only the route identifier is loaded on the server; account data is fetched in the browser.
export const load: PageLoad = ({ params }) => ({ id: params.id });
