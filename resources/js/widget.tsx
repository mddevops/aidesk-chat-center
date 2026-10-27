import {createRoot} from 'react-dom/client';
import {WidgetRuntime} from './widgets/WidgetRuntime';
const props = JSON.parse(document.getElementById('widget-config')!.textContent!);
createRoot(document.getElementById('widget-root')!).render(<WidgetRuntime {...props} />);
