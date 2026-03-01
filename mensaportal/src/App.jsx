import React, { useState, useEffect } from 'react';
import { PayPalScriptProvider, PayPalButtons } from "@paypal/react-paypal-js";
import { 
  CreditCard, 
  Wallet, 
  History, 
  User, 
  Plus, 
  CheckCircle2, 
  XCircle, 
  LogOut, 
  Utensils, 
  CalendarDays,
  Smartphone,
  Banknote,
  ShieldCheck,
  AlertCircle,
  ChevronRight,
  ChevronLeft,
  ShoppingCart,
  Landmark,
  Loader2
} from 'lucide-react';

// Map für dynamisches Zuweisen von Icons aus dem Backend
const IconMap = {
  Utensils, Wallet, Banknote, CalendarDays, CreditCard
};

// --- PAYPAL KONFIGURATION ---
const paypalOptions = {
  clientId: "***REMOVED***",
  currency: "EUR",
  intent: "capture"
};

// --- COMPONENTS ---
const Modal = ({ isOpen, onClose, title, children }) => {
  if (!isOpen) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 animate-in fade-in duration-200">
      <div className="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden animate-in zoom-in-95 duration-200 flex flex-col max-h-[90vh]">
        <div className="flex justify-between items-center p-6 border-b border-slate-100 shrink-0">
          <h3 className="text-lg font-bold text-slate-800">{title}</h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600 transition-colors">
            <XCircle size={24} />
          </button>
        </div>
        <div className="p-6 overflow-y-auto">
          {children}
        </div>
      </div>
    </div>
  );
};

// --- LEGAL TEXT COMPONENT (DYNAMISCH) ---
const LegalText = ({ type, htmlContent, isLoading }) => {
  const title = type === 'impressum' ? 'Impressum' : 'Datenschutzerklärung';
  
  // Kleiner Fix: Falls "className" im DB-String steht (React Syntax), ersetzen wir es durch "class" (HTML Syntax)
  const sanitizedContent = htmlContent ? htmlContent.replace(/className=/g, 'class=') : '';

  return (
  <div className="bg-white p-8 rounded-3xl border border-slate-100 shadow-sm text-slate-700 space-y-4 text-left">
      <h2 className="text-2xl font-bold mb-4 text-slate-800">{title}</h2>
      {isLoading ? (
        <div className="flex flex-col items-center justify-center py-12 gap-4">
          <Loader2 className="animate-spin text-blue-600" size={40} />
          <p className="text-sm text-slate-400 animate-pulse">Inhalte werden geladen...</p>
        </div>
      ) : sanitizedContent ? (
        <div 
          className="space-y-4 text-sm leading-relaxed prose prose-slate max-w-none"
          dangerouslySetInnerHTML={{ __html: sanitizedContent }}
        />
    ) : (
        <div className="p-8 text-center bg-slate-50 rounded-2xl border-2 border-dashed border-slate-200">
           <p className="text-slate-400 text-sm">Inhalt konnte nicht geladen werden.</p>
        </div>
    )}
  </div>
);
};

// --- DYNAMISCHE CHECKOUT KOMPONENTE (Paypal/Klarna/Manuell) ---
const CheckoutAction = ({ amount, paymentMethod, actionType, actionData, onManualSubmit, onSucceed, onProcessing, onFinished, isLoading, user, config }) => {
  const paypalRef = React.useRef(null);
  const [isScriptLoaded, setIsScriptLoaded] = React.useState(false);
  const [isKlarnaLoaded, setIsKlarnaLoaded] = React.useState(false);
  const [klarnaToken, setKlarnaToken] = React.useState(null);

  // FIX: Wir machen einen String aus dem actionData Objekt, um Dauerschleifen in React-Hooks zu vermeiden, 
  // da Inline-Objekte in React bei jedem Render als "neu" betrachtet werden.
  const actionDataString = JSON.stringify(actionData);

  React.useEffect(() => {
    if (paymentMethod !== 'paypal') return;

    const scriptId = 'paypal-sdk-script';
    let script = document.getElementById(scriptId);

    if (script) {
      setIsScriptLoaded(true);
      return;
    }

    script = document.createElement("script");
    script.id = scriptId;
    script.src = `https://www.paypal.com/sdk/js?client-id=***REMOVED***&currency=EUR&intent=capture&components=buttons`;
    script.async = true;
    
    script.onload = () => setIsScriptLoaded(true);
    document.body.appendChild(script);
  }, [paymentMethod]);

  // Klarna Native SDK Laden
  React.useEffect(() => {
    if (paymentMethod !== 'klarna') return;
    const scriptId = 'klarna-sdk-script';
    if (!document.getElementById(scriptId)) {
        const script = document.createElement("script");
        script.id = scriptId;
        script.src = `https://x.klarnacdn.net/kp/lib/v1/api.js`;
        script.async = true;
        script.onload = () => setIsKlarnaLoaded(true);
        document.body.appendChild(script);
    } else {
        setIsKlarnaLoaded(true);
    }
  }, [paymentMethod]);

  React.useEffect(() => {
    if (isScriptLoaded && window.paypal && paypalRef.current && paymentMethod === 'paypal') {
      paypalRef.current.innerHTML = '';
      
      try {
        window.paypal.Buttons({
          style: { layout: "vertical", shape: "rect", color: 'gold' },
          createOrder: () => {
            return fetch("/api/actions.php?action=create_paypal_order", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ 
                amount: amount, 
                actionType: actionType, 
                actionData: JSON.parse(actionDataString) // Hier nutzen wir den String als Quelle
              }),
            })
            .then(res => res.json())
            .then(orderData => {
              if (orderData.id) {
                return orderData.id;
              } else {
                throw new Error(orderData.message || orderData.error || "Fehler beim Erstellen der Order");
              }
            })
            .catch(error => {
              console.error("PayPal createOrder Fehler:", error);
              alert(error.message || "Konnte keine Verbindung zu PayPal herstellen.");
              throw error;
            });
          },
          onApprove: (data, actions) => {
            if (onProcessing) onProcessing(); 
            return fetch("/api/actions.php?action=capture_paypal_order", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ orderID: data.orderID })
            })
            .then(res => res.json())
            .then(result => {
              if (result.status === 'success' || result.status === 'COMPLETED') {
                onSucceed();
              } else {
                throw new Error(result.message || "Zahlung konnte nicht abgeschlossen werden.");
              }
            })
            .catch(error => {
              console.error("PayPal onApprove Fehler:", error);
              alert(error.message || "Sorry, die Zahlung konnte nicht verarbeitet werden.");
            })
            .finally(() => {
              if (onFinished) onFinished(); 
            });
          },
          onError: (err) => {
            console.error("PayPal Error:", err);
            alert("Es gab ein Problem bei der Zahlungsabwicklung.");
          }
        }).render(paypalRef.current);
      } catch (err) {
        console.error("PayPal Render Error:", err);
      }
    }
  // actionDataString als Dependency, anstatt das direkte Objekt
  }, [isScriptLoaded, paymentMethod, amount, actionType, actionDataString]);

  // 1. SCHRITT: Klarna Session anfordern
  React.useEffect(() => {
    if (isKlarnaLoaded && window.Klarna && paymentMethod === 'klarna') {
        if (onProcessing) onProcessing();
        setKlarnaToken(null); // Reset falls sich Parameter ändern
        
        fetch("/api/actions.php?action=create_klarna_session", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ amount, actionType, actionData: JSON.parse(actionDataString) }),
        })
        .then(res => res.json())
        .then(data => {
            if (data.client_token) {
                setKlarnaToken(data.client_token);
            } else {
                throw new Error(data.message || "Klarna Initialisierung fehlgeschlagen.");
            }
        })
        .catch(err => {
            alert(err.message);
        })
        .finally(() => {
            // FIX: Beendet den Lade-Spinner, damit der User auf den Bezahlen-Button klicken kann
            if (onFinished) onFinished();
        });
    }
  // actionDataString als sichere Dependency
  }, [isKlarnaLoaded, paymentMethod, amount, actionType, actionDataString]);

  // 2. SCHRITT: Klarna Widget erst laden, wenn React das Div in den DOM eingefügt hat
  React.useEffect(() => {
    if (klarnaToken && window.Klarna) {
        // setTimeout gibt dem Browser Zeit für den Render-Zyklus, damit das Element garantiert existiert
        setTimeout(() => {
            const container = document.getElementById('klarna-payments-container');
            if (container) {
                window.Klarna.Payments.init({ client_token: klarnaToken });
                window.Klarna.Payments.load({ container: '#klarna-payments-container' }, () => {
                   // Optional callback
                });
            }
        }, 100);
    }
  }, [klarnaToken]);

  // Zahlung nach Nutzerinteraktion bei Klarna finalisieren
  const handleKlarnaAuth = () => {
    if (!window.Klarna) return;
    if (onProcessing) onProcessing();
    window.Klarna.Payments.authorize({}, (res) => {
        if (res.approved && res.authorization_token) {
            fetch("/api/actions.php?action=place_klarna_order", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ authorization_token: res.authorization_token })
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    onSucceed();
                } else {
                    alert(data.message || "Fehler bei der Klarna-Zahlung.");
                }
            })
            .catch(() => alert("Verbindungsfehler."))
            .finally(() => { if (onFinished) onFinished(); });
        } else {
            if (onFinished) onFinished();
            if (res.error) alert(res.error.message || "Klarna-Zahlung abgebrochen.");
        }
    });
  };

  if (amount <= 0) {
    return (
      <button 
        disabled={isLoading}
        onClick={() => onManualSubmit('Guthaben')}
        className="w-full py-4 px-6 rounded-xl font-bold flex items-center justify-center gap-2 transition-all bg-blue-600 text-white hover:bg-blue-700 shadow-sm disabled:opacity-50"
      >
        {isLoading && <Loader2 className="animate-spin" size={20} />}
        Kostenpflichtig bestellen (Guthaben)
      </button>
    );
  }

  if (paymentMethod === 'ueberweisung') {
    return (
      <div className="space-y-4 animate-in fade-in duration-300">
        <div className="bg-blue-50 text-blue-900 p-5 rounded-xl text-sm border border-blue-200 shadow-inner">
          <p className="font-bold mb-3 flex items-center gap-2"><Landmark size={18}/> Bitte überweise den Betrag an:</p>
          <div className="space-y-2 font-mono">
            <p className="flex justify-between"><span>Empfänger:</span> <strong>{config.schoolName}</strong></p>
            <p className="flex justify-between"><span>IBAN:</span> <strong>{config.schoolIban}</strong></p>
            <p className="flex justify-between"><span>BIC:</span> <strong>{config.schoolBic}</strong></p>
            <div className="mt-3 pt-3 border-t border-blue-200">
              <p className="text-xs text-blue-700 uppercase tracking-wider font-sans font-bold mb-1">Verwendungszweck (WICHTIG):</p>
              <p className="font-bold text-lg bg-white px-3 py-2 rounded border border-blue-100 text-center select-all shadow-sm">
                Wird im nächsten Schritt generiert
              </p>
            </div>
          </div>
        </div>
        <button 
          disabled={isLoading}
          onClick={() => onManualSubmit('Überweisung')}
          className="w-full py-4 px-6 rounded-xl font-bold flex items-center justify-center gap-2 transition-all bg-blue-600 text-white hover:bg-blue-700 shadow-sm disabled:opacity-50"
        >
          {isLoading && <Loader2 className="animate-spin" size={20} />}
          Bestellung abschließen (Vorkasse)
        </button>
        <p className="text-xs text-slate-500 text-center">Dein Account wird aktualisiert, sobald das Geld eingegangen ist.</p>
      </div>
    );
  }

  return (
        <div className="space-y-4 relative z-0 min-h-[150px] animate-in fade-in duration-300">
          {paymentMethod === 'paypal' && !isScriptLoaded ? (
        <div className="flex justify-center items-center h-[150px]">
          <Loader2 className="animate-spin text-blue-600" size={32} />
        </div>
      ) : null}
          
          {paymentMethod === 'paypal' && (
      <div ref={paypalRef} className={!isScriptLoaded ? 'hidden' : 'block'}></div>
          )}

          {paymentMethod === 'klarna' && (
            <div className="animate-in fade-in duration-300 w-full">
              {!klarnaToken ? (
                <div className="flex justify-center items-center h-[150px]">
                  <Loader2 className="animate-spin text-[#ff8da1]" size={32} />
                </div>
              ) : (
                <>
                  {/* Container, in den Klarna automatisch seine Input-Felder rendert */}
                  <div id="klarna-payments-container" className="mb-4 bg-white border border-slate-200 rounded-xl p-2 min-h-[100px]"></div>
                  
                  {/* Der offizielle Kauf-Button, der die Authorisierung anstößt */}
                  <button 
                      disabled={isLoading}
                      onClick={handleKlarnaAuth}
                      className="w-full py-4 px-6 rounded-xl font-bold flex items-center justify-center gap-2 transition-all bg-[#ffb3c7] text-[#1c1c1c] hover:bg-[#ff8da1] shadow-sm disabled:opacity-50"
                  >
                      {isLoading ? <Loader2 className="animate-spin" size={20} /> : <ShoppingCart size={20} />}
                      Jetzt mit Klarna bezahlen
                  </button>
                </>
              )}
            </div>
          )}
    </div>
  );
};

export default function App() {
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [authMode, setAuthMode] = useState('login'); 
  const [activeTab, setActiveTab] = useState('dashboard');
  
  const [isLoading, setIsLoading] = useState(false);         
  const [isActionLoading, setIsActionLoading] = useState(false); 
  const [isAuthLoading, setIsAuthLoading] = useState(false);     
  
  const [user, setUser] = useState(null);
  const [transactions, setTransactions] = useState([]);
  const [abos, setAbos] = useState([]);
  const [cards, setCards] = useState([]);
  
  // Dynamische Preise und Einstellungen aus der Datenbank
  const [sysPrices, setSysPrices] = useState({ 
    cardDeposit: 5, 
    halfYear: 80, 
    fullYear: 120,
    schoolName: 'Gymnasium Hohenschwangau',
    schoolIban: 'DE12 3456 7890 1234 5678 90',
    schoolBic: 'BYLADEM1ALG'
  });

  // KORREKTUR: Schlüssel im State an activeTab Werte anpassen
  const [legalContent, setLegalContent] = useState({ impressum: '', datenschutz: '' });
  const [isLegalLoading, setIsLegalLoading] = useState(false);

  const [visibleTransactions, setVisibleTransactions] = useState(10);
  const [authData, setAuthData] = useState({ firstName: '', lastName: '', email: '', password: '', passwordConfirm: '' });
  const [authError, setAuthError] = useState('');
  
  const [resetToken, setResetToken] = useState('');
  const [resetSuccessMsg, setResetSuccessMsg] = useState('');
  const [showExpiredAbos, setShowExpiredAbos] = useState(false);

  const handleLogout = () => {
    fetch('/api/data.php?action=logout', { credentials: 'include' })
      .catch(err => console.error("Logout error", err))
      .finally(() => {
        setIsLoggedIn(false);
        setUser(null);
        setTransactions([]);
        setAbos([]);
        setCards([]);
        setVisibleTransactions(10);
      });
  };

  const fetchUserData = (showLiquid = false) => {
    if (showLiquid) setIsLoading(true);
    
    fetch('/api/data.php?action=getData', { credentials: 'include' })
      .then(response => {
        if (!response.ok) throw new Error('Netzwerk-Antwort war nicht ok');
        return response.json();
      })
      .then(data => {
        if (data.status === 'success') {
          setIsLoggedIn(true);
          setUser(data.data.user);
          setAbos(data.data.abos);
          setCards(data.data.cards);
          
          // Konfiguration mappen
          if (data.data.config) {
            const c = data.data.config;
            setSysPrices({
              cardDeposit: parseFloat(c.card_deposit) || 5,
              halfYear: parseFloat(c.half_year_per_day) || 80,
              fullYear: parseFloat(c.full_year_per_day) || 120,
              schoolName: c.school_name || 'Gymnasium Hohenschwangau',
              schoolIban: c.school_iban || 'DE12 3456 7890 1234 5678 90',
              schoolBic: c.school_bic || 'BYLADEM1ALG'
            });
          }
          
          const mappedTransactions = data.data.transactions.map(tx => ({
            ...tx,
            icon: IconMap[tx.iconName] || Wallet 
          }));
          setTransactions(mappedTransactions);
        } else {
          if (isLoggedIn) handleLogout();
          if (data.message && data.status !== 'unauthorized') {
             setAuthError(data.message);
          }
        }
      })
      .catch(err => {
        console.error("Fehler beim API-Abruf.", err);
        if (isLoggedIn) handleLogout();
        setAuthError("Verbindung zum Server fehlgeschlagen. Bitte erneut einloggen.");
      })
      .finally(() => {
        if (showLiquid) {
          setTimeout(() => {
            setIsLoading(false);
          }, 1200);
        }
      });
  };

  // --- FUNKTIONEN ZUM LADEN DER RECHTSTEXTE (ON DEMAND) ---
  const fetchImprint = () => {
    if (legalContent.impressum) return; 
    setIsLegalLoading(true);
    fetch('/api/data.php?action=getLegalContent&type=imprint')
      .then(r => r.json())
      .then(data => {
        if (data.status === 'success') {
          setLegalContent(prev => ({ ...prev, impressum: data.content }));
        }
      })
      .catch(err => console.error("Impressum Fehler", err))
      .finally(() => setIsLegalLoading(false));
  };

  const fetchPrivacy = () => {
    if (legalContent.datenschutz) return; 
    setIsLegalLoading(true);
    fetch('/api/data.php?action=getLegalContent&type=privacy')
      .then(r => r.json())
      .then(data => {
        if (data.status === 'success') {
          setLegalContent(prev => ({ ...prev, datenschutz: data.content }));
        }
      })
      .catch(err => console.error("Datenschutz Fehler", err))
      .finally(() => setIsLegalLoading(false));
  };

  useEffect(() => {
    fetchUserData(false);

    const urlParams = new URLSearchParams(window.location.search);
    const token = urlParams.get('token');
    const passwortVergessen = urlParams.get('passwort_vergessen');
    if (token) {
      setAuthMode('reset_password');
      setResetToken(token);
    } else if (passwortVergessen) {
      setAuthMode('forgot_password');
    }
  }, []);
  
  // Effekt zum Triggern der Legal-Fetches
  useEffect(() => {
    if (activeTab === 'impressum' || authMode === 'impressum') fetchImprint();
    if (activeTab === 'datenschutz' || authMode === 'datenschutz') fetchPrivacy();
  }, [activeTab, authMode]);
  
  const [aboStep, setAboStep] = useState(1);
  const [aboPayment, setAboPayment] = useState('paypal');
  const [shopData, setShopData] = useState({
    type: null, 
    days: [],
    cardOption: 'existing', 
    selectedHolderId: '',
    newStudent: { firstName: '', lastName: '', class: '' },
    useBalance: false
  });
  
  const [isTopUpOpen, setIsTopUpOpen] = useState(false);
  const [topUpStep, setTopUpStep] = useState('choose');
  const [topUpAmount, setTopUpAmount] = useState(20);
  const [topUpPayment, setTopUpPayment] = useState('paypal');
  const [paymentPin, setPaymentPin] = useState(''); 

  const [isOrderCardOpen, setIsOrderCardOpen] = useState(false);
  const [orderCardStep, setOrderCardStep] = useState('form');
  const [orderCardPayment, setOrderCardPayment] = useState('paypal');
  const [newCardData, setNewCardData] = useState({ firstName: '', lastName: '', class: '', useBalance: false });

  const [isLostCardOpen, setIsLostCardOpen] = useState(false);
  const [lostCardStep, setLostCardStep] = useState('choose');
  const [lostCardData, setLostCardData] = useState({ card: null, useBalance: false, paymentMethod: 'paypal' });

  const [isToastOpen, setIsToastOpen] = useState(false);
  const [toastMessage, setToastMessage] = useState('');

  const showToast = (msg) => {
    setToastMessage(msg);
    setIsToastOpen(true);
    setTimeout(() => setIsToastOpen(false), 3000);
  };

  const handleLogin = (e) => {
    if (e) e.preventDefault();
    setAuthError('');
    setIsAuthLoading(true);

    fetch('/api/data.php?action=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ email: authData.email, passwort: authData.password })
    })
    .then(r => r.json())
    .then(data => {
      if (data.status === 'success') {
        setIsAuthLoading(false);
        fetchUserData(true); 
      } else {
        setAuthError(data.message || 'Login fehlgeschlagen');
        setIsAuthLoading(false);
      }
    })
    .catch(() => {
      setAuthError('Netzwerkfehler. Bitte Server prüfen.');
      setIsAuthLoading(false);
    });
  };

  const handleRegister = (e) => {
    e.preventDefault();
    setAuthError('');
    if (authData.password !== authData.passwordConfirm) {
      setAuthError('Die Passwörter stimmen nicht überein.');
      return;
    }
    setIsAuthLoading(true);
    fetch('/api/data.php?action=register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        vorname: authData.firstName,
        nachname: authData.lastName,
        email: authData.email,
        passwort: authData.password,
        passwort2: authData.passwordConfirm
      })
    })
    .then(r => r.json())
    .then(data => {
      if (data.status === 'success') {
        setIsAuthLoading(false);
        handleLogin();
      } else {
        setAuthError(data.message || 'Registrierung fehlgeschlagen');
        setIsAuthLoading(false);
      }
    })
    .catch(() => {
      setAuthError('Netzwerkfehler. Bitte Server prüfen.');
      setIsAuthLoading(false);
    });
  };

  const handleForgotPassword = (e) => {
    e.preventDefault();
    setAuthError('');
    setResetSuccessMsg('');
    setIsAuthLoading(true);
    fetch('/api/data.php?action=forgot_password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ email: authData.email })
    })
    .then(r => r.json())
    .then(data => {
      if (data.status === 'success') {
        setResetSuccessMsg('Falls diese E-Mail registriert ist, haben wir dir einen Link zum Zurücksetzen gesendet.');
        setAuthData({...authData, email: ''});
      } else {
        setAuthError(data.message || 'Ein Fehler ist aufgetreten.');
      }
    })
    .catch(() => setAuthError('Netzwerkfehler. Bitte Server prüfen.'))
    .finally(() => setIsAuthLoading(false));
  };

  const handleResetPassword = (e) => {
    e.preventDefault();
    setAuthError('');
    setResetSuccessMsg('');
    if (authData.password !== authData.passwordConfirm) {
      setAuthError('Die Passwörter stimmen nicht überein.');
      return;
    }
    setIsAuthLoading(true);
    fetch('/api/data.php?action=reset_password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ token: resetToken, passwort: authData.password })
    })
    .then(r => r.json())
    .then(data => {
      if (data.status === 'success') {
        setAuthMode('login');
        setResetSuccessMsg('Passwort erfolgreich geändert. Du kannst dich nun einloggen.');
        setAuthData({...authData, password: '', passwordConfirm: ''});
        window.history.pushState({}, document.title, window.location.pathname); 
      } else {
        setAuthError(data.message || 'Fehler beim Zurücksetzen des Passworts.');
      }
    })
    .catch(() => setAuthError('Netzwerkfehler. Bitte Server prüfen.'))
    .finally(() => setIsAuthLoading(false));
  };

  const handleManualTopUp = (method) => {
    setIsActionLoading(true);
    fetch('/api/actions.php?action=topup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ amount: Number(topUpAmount), paymentMethod: method })
    })
    .then(r => r.json())
    .then(data => {
      if(data.status === 'success') {
        if (method === 'Überweisung' && data.payment_pin) {
          setPaymentPin(data.payment_pin);
          setTopUpStep('success-ueberweisung');
        } else {
        showToast(`Die Aufladung über ${method} wurde initiiert.`);
        setIsTopUpOpen(false);
        }
        fetchUserData(false);
      } else {
        showToast(data.message || 'Fehler beim Aufladen.');
      }
    })
    .catch(err => {
      console.error(err);
      showToast('Verbindungsfehler. Bitte versuche es erneut.');
    })
    .finally(() => setIsActionLoading(false));
  };

  const handleManualOrderCard = (method) => {
    setIsActionLoading(true);
    fetch('/api/actions.php?action=order_card', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        firstName: newCardData.firstName,
        lastName: newCardData.lastName,
        class: newCardData.class,
        useBalance: newCardData.useBalance,
        paymentMethod: method
      })
    })
    .then(r => r.json())
    .then(data => {
      if(data.status === 'success') {
        if (method === 'Überweisung' && data.payment_pin) {
          setPaymentPin(data.payment_pin);
        }
        setOrderCardStep('success');
        fetchUserData(false);
      } else {
        showToast(data.message || 'Fehler beim Bestellen.');
      }
    })
    .catch(err => {
      console.error(err);
      showToast('Verbindungsfehler. Bitte versuche es erneut.');
    })
    .finally(() => setIsActionLoading(false));
  };

  const handleBlockCard = () => {
    setIsActionLoading(true);
    fetch('/api/actions.php?action=block_card', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ holderId: lostCardData.card.holderId, cardId: lostCardData.card.id })
    })
    .then(r => r.json())
    .then(data => {
      if(data.status === 'success') {
        setLostCardStep('success-block');
        fetchUserData(false);
      } else {
        showToast(data.message || 'Fehler beim Sperren der Karte.');
      }
    })
    .catch(err => {
      console.error(err);
      showToast('Verbindungsfehler. Bitte versuche es erneut.');
    })
    .finally(() => setIsActionLoading(false));
  };

  const handleManualReorderCard = (method) => {
    setIsActionLoading(true);
    fetch('/api/actions.php?action=reorder_card', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        holderId: lostCardData.card.holderId,
        useBalance: lostCardData.useBalance,
        paymentMethod: method
      })
    })
    .then(r => r.json())
    .then(data => {
      if(data.status === 'success') {
        if (method === 'Überweisung' && data.payment_pin) {
          setPaymentPin(data.payment_pin);
        }
        setLostCardStep('success-reorder');
        fetchUserData(false);
      } else {
        showToast(data.message || 'Fehler beim Beantragen der Ersatzkarte.');
      }
    })
    .catch(err => {
      console.error(err);
      showToast('Verbindungsfehler. Bitte versuche es erneut.');
    })
    .finally(() => setIsActionLoading(false));
  };

  const handleManualBuyAbo = (method) => {
    setIsActionLoading(true);
    fetch('/api/actions.php?action=buy_abo', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        type: shopData.type,
        days: shopData.days,
        cardOption: shopData.cardOption,
        selectedHolderId: shopData.selectedHolderId,
        newStudent: shopData.newStudent,
        useBalance: shopData.useBalance,
        paymentMethod: method
      })
    })
    .then(r => r.json())
    .then(data => {
      if(data.status === 'success') {
        if (method === 'Überweisung' && data.payment_pin) {
          setPaymentPin(data.payment_pin);
        }
        if (shopData.cardOption === 'new' || method === 'Überweisung') {
          setAboStep(5);
          fetchUserData(false);
        } else {
          showToast("Abo erfolgreich gebucht!");
          setActiveTab('abos');
          fetchUserData(false);
        }
      } else {
        showToast(data.message || 'Fehler beim Abo-Kauf.');
      }
    })
    .catch(err => {
      console.error(err);
      showToast('Verbindungsfehler. Bitte versuche es erneut.');
    })
    .finally(() => setIsActionLoading(false));
  };

  const openTopUpModal = () => {
    setTopUpStep('choose');
    setTopUpAmount(20);
    setTopUpPayment('paypal');
    setIsTopUpOpen(true);
  };
  
  const openOrderCardModal = () => {
    setOrderCardStep('form');
    setOrderCardPayment('paypal');
    setNewCardData({ firstName: '', lastName: '', class: '', useBalance: false });
    setIsOrderCardOpen(true);
  };

  const openLostCardModal = (card) => {
    setLostCardData({ card: card, useBalance: false, paymentMethod: 'paypal' });
    setLostCardStep('choose');
    setIsLostCardOpen(true);
  };

  const openBlockCardModal = (card) => {
    setLostCardData({ card: card, useBalance: false, paymentMethod: 'paypal' });
    setLostCardStep('confirm-block');
    setIsLostCardOpen(true);
  };

  const openAboShop = () => {
    setAboStep(1);
    setAboPayment('paypal');
    setShopData({
      type: null,
      days: [],
      cardOption: 'existing',
      selectedHolderId: cards.length > 0 ? cards[0].holderId : '',
      newStudent: { firstName: '', lastName: '', class: '' },
      useBalance: false
    });
    setActiveTab('abo-shop');
  };

  const calculateAboPrice = () => {
    if (!shopData.type) return 0;
    const basePrice = shopData.type === 'halb' ? sysPrices.halfYear : sysPrices.fullYear;
    const daysCost = shopData.days.length * basePrice;
    const cardCost = shopData.cardOption === 'new' ? sysPrices.cardDeposit : 0;
    return daysCost + cardCost;
  };

  const renderAboCard = (abo) => (
    <div key={abo.id} className={`bg-white rounded-3xl border ${abo.isActive ? 'border-green-200 ring-1 ring-green-100' : 'border-slate-200'} shadow-sm overflow-hidden relative`}>
      <div className={`px-6 py-3 border-b flex justify-between items-center ${abo.isActive ? 'bg-green-50 border-green-100' : 'bg-slate-50 border-slate-100'}`}>
        <span className="font-bold text-slate-800">{abo.type}</span>
        <span className={`text-xs font-bold px-3 py-1 rounded-full flex items-center gap-1 ${abo.isActive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
          {abo.isActive ? <CheckCircle2 size={14} /> : <AlertCircle size={14} />}
          {abo.isActive ? 'Aktiv' : 'Abgelaufen'}
        </span>
      </div>
      <div className="p-6">
        <div className="flex items-center gap-3 mb-6">
          <div className="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center text-slate-400">
            <User size={24} />
          </div>
          <div>
            <p className="text-xs text-slate-500 font-medium uppercase tracking-wider">Schüler/in</p>
            <p className="font-bold text-slate-800 text-lg">{abo.student}</p>
          </div>
        </div>
        <div className="space-y-4">
          <div>
            <p className="text-xs text-slate-500 mb-2">Gültige Wochentage</p>
            <div className="flex gap-2">
              {['Mo', 'Di', 'Mi', 'Do', 'Fr'].map(day => (
                <span key={day} className={`w-8 h-8 flex items-center justify-center rounded-lg text-sm font-semibold ${abo.days.includes(day) ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-400'}`}>
                  {day}
                </span>
              ))}
            </div>
          </div>
          <div className="flex flex-col gap-3 pt-4 border-t border-slate-100">
            <div className="flex justify-between items-center">
              <span className="text-sm text-slate-500">Bisher genutzt:</span>
              <span className="font-semibold text-slate-800 flex items-center gap-1">
                <Utensils size={14} className="text-slate-400" /> {abo.usageCount}x
              </span>
            </div>
            <div className="flex justify-between items-center">
              <span className="text-sm text-slate-500">Gültig bis:</span>
              <span className={`font-semibold ${abo.isActive ? 'text-slate-800' : 'text-red-600'}`}>{abo.validUntil}</span>
            </div>
          </div>
        </div>
      </div>
      {!abo.isActive && (
        <div className="p-4 bg-slate-50 border-t border-slate-100">
           <button onClick={() => showToast("Abo wird verlängert...")} className="w-full py-2 bg-white border border-slate-200 text-slate-700 font-semibold rounded-lg hover:bg-slate-100 transition-colors">
             Abo verlängern
           </button>
        </div>
      )}
    </div>
  );

  if (isLoading) {
    return (
      <div className="fixed inset-0 z-[100] flex flex-col items-center justify-center bg-blue-600 overflow-hidden">
        <style>
          {`
            @keyframes liquid-morph {
              0% { border-radius: 60% 40% 30% 70% / 60% 30% 70% 40%; transform: rotate(0deg); }
              50% { border-radius: 30% 60% 70% 40% / 50% 60% 30% 60%; transform: rotate(180deg); }
              100% { border-radius: 60% 40% 30% 70% / 60% 30% 70% 40%; transform: rotate(360deg); }
            }
            @keyframes float {
              0%, 100% { transform: translateY(0); }
              50% { transform: translateY(-20px); }
            }
            .liquid-shape {
              animation: liquid-morph 4s linear infinite;
              background: linear-gradient(135deg, rgba(255,255,255,0.9), rgba(255,255,255,0.3));
              backdrop-filter: blur(8px);
              box-shadow: 0 15px 35px rgba(0,0,0,0.1), inset 0 0 20px rgba(255,255,255,0.8);
            }
            .liquid-container {
              animation: float 3s ease-in-out infinite;
            }
          `}
        </style>
        <div className="liquid-container relative w-48 h-48 flex items-center justify-center">
          <div className="liquid-shape absolute inset-0"></div>
          <div className="liquid-shape absolute inset-0 opacity-40 mix-blend-overlay" style={{ animationDirection: 'reverse', animationDuration: '6s' }}></div>
          <div className="liquid-shape absolute inset-4 opacity-60 bg-blue-100" style={{ animationDuration: '3s' }}></div>
          <Utensils size={56} className="text-blue-600 relative z-10 animate-pulse drop-shadow-md" />
        </div>
        <h2 className="text-white text-xl font-bold mt-12 animate-pulse tracking-[0.2em] relative z-10">DATEN WERDEN GELADEN</h2>
      </div>
    );
  }

  if (!isLoggedIn || !user) {
    return (
      <div className="min-h-screen bg-slate-50 flex flex-col justify-center items-center p-4">
        
        {/* Impressum & Datenschutz Views VOR dem Login */}
        {(authMode === 'impressum' || authMode === 'datenschutz') ? (
           <div className="w-full max-w-2xl mt-8">
             <button onClick={() => setAuthMode('login')} className="flex items-center gap-2 text-slate-500 font-bold text-sm mb-4 hover:text-slate-800 transition-colors">
               <ChevronLeft size={16} /> Zurück zum Login
             </button>
             <LegalText type={authMode} htmlContent={legalContent[authMode]} isLoading={isLegalLoading} />
           </div>
        ) : (
        <>
          <div className="w-full max-w-md bg-white rounded-3xl shadow-xl overflow-hidden border border-slate-100">
            <div className="bg-blue-600 p-8 text-center">
              <div className="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center mx-auto mb-4 backdrop-blur-sm">
                <Utensils className="text-white" size={32} />
              </div>
              <h1 className="text-2xl font-bold text-white">MensaPay</h1>
              <p className="text-blue-100 mt-1">Das Ho'gauer Schulverpflegungs-Portal</p>
            </div>
            <div className="p-8">
              <h2 className="text-xl font-bold text-slate-800 mb-6">
                {authMode === 'login' && 'Willkommen zurück!'}
                {authMode === 'register' && 'Eltern-Account erstellen'}
                {authMode === 'forgot_password' && 'Passwort zurücksetzen'}
                {authMode === 'reset_password' && 'Neues Passwort vergeben'}
              </h2>
              {authError && (
                <div className="mb-4 p-3 bg-red-50 border border-red-200 text-red-600 rounded-xl text-sm font-medium flex items-center gap-2">
                  <AlertCircle size={16} /> {authError}
                </div>
              )}
              {resetSuccessMsg && (
                <div className="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm font-medium flex items-center gap-2">
                  <CheckCircle2 size={16} /> {resetSuccessMsg}
                </div>
              )}
              <form 
                onSubmit={
                  authMode === 'login' ? handleLogin : 
                  authMode === 'register' ? handleRegister : 
                  authMode === 'forgot_password' ? handleForgotPassword :
                  handleResetPassword
                } 
                className="space-y-4"
              >
                {authMode === 'register' && (
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-slate-700 mb-1">Vorname (Elternteil)</label>
                      <input required type="text" value={authData.firstName} onChange={e => setAuthData({...authData, firstName: e.target.value})} className="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all" placeholder="Anna" />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-slate-700 mb-1">Nachname</label>
                      <input required type="text" value={authData.lastName} onChange={e => setAuthData({...authData, lastName: e.target.value})} className="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all" placeholder="Mustermann" />
                    </div>
                  </div>
                )}
                {(authMode === 'login' || authMode === 'register' || authMode === 'forgot_password') && (
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">E-Mail Adresse</label>
                    <input required type="email" value={authData.email} onChange={e => setAuthData({...authData, email: e.target.value})} className="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all" placeholder="mail@beispiel.de" />
                  </div>
                )}
                {(authMode === 'login' || authMode === 'register' || authMode === 'reset_password') && (
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">{authMode === 'reset_password' ? 'Neues Passwort' : 'Passwort'}</label>
                    <input required type="password" value={authData.password} onChange={e => setAuthData({...authData, password: e.target.value})} className="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all" placeholder="••••••••" />
                  </div>
                )}
                {(authMode === 'register' || authMode === 'reset_password') && (
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">Passwort wiederholen</label>
                    <input required type="password" value={authData.passwordConfirm} onChange={e => setAuthData({...authData, passwordConfirm: e.target.value})} className="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all" placeholder="••••••••" />
                  </div>
                )}
                <button 
                  type="submit" 
                  disabled={isAuthLoading}
                  className="w-full py-3 px-4 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white font-semibold rounded-xl transition-colors shadow-lg shadow-blue-600/20 mt-6 flex justify-center items-center gap-2"
                >
                  {isAuthLoading ? (
                    <>
                      <Loader2 className="animate-spin" size={20} />
                      Bitte warten...
                    </>
                  ) : (
                    authMode === 'login' ? 'Einloggen' : 
                    authMode === 'register' ? 'Registrieren' :
                    authMode === 'forgot_password' ? 'Link anfordern' :
                    'Passwort speichern'
                  )}
                </button>
              </form>
              <div className="mt-6 text-center space-y-3">
                {(authMode === 'login' || authMode === 'register' || authMode === 'forgot_password') && (
                  <button 
                    type="button"
                    disabled={isAuthLoading}
                    onClick={() => {
                      setAuthMode(authMode === 'login' ? 'register' : 'login');
                      setAuthError('');
                      setResetSuccessMsg('');
                    }}
                    className="block w-full text-sm text-slate-500 hover:text-blue-600 disabled:opacity-50 font-medium transition-colors"
                  >
                    {authMode === 'login' ? 'Noch kein Account? Hier registrieren.' : 'Bereits registriert? Hier einloggen.'}
                  </button>
                )}
                {authMode === 'login' && (
                  <button 
                    type="button" 
                    onClick={() => { setAuthMode('forgot_password'); setAuthError(''); setResetSuccessMsg(''); }} 
                    className="block w-full text-sm text-blue-500 hover:text-blue-700 font-medium transition-colors"
                  >
                    Passwort vergessen?
                  </button>
                )}
              </div>
            </div>
          </div>
          <div className="mt-8 text-center text-sm text-slate-500 flex justify-center gap-6">
            <button onClick={() => setAuthMode('impressum')} className="hover:text-blue-600 transition-colors">Impressum</button>
            <button onClick={() => setAuthMode('datenschutz')} className="hover:text-blue-600 transition-colors">Datenschutz</button>
          </div>
        </>
        )}
      </div>
    );
  }

  return (
      <div className="min-h-screen bg-slate-50 text-slate-800 font-sans pb-20 md:pb-0 flex flex-col">
        <header className="bg-white border-b border-slate-200 sticky top-0 z-30">
          <div className="max-w-5xl mx-auto px-4 h-16 flex items-center justify-between">
            <div className="flex items-center gap-2 text-blue-600">
              <Utensils size={24} className="stroke-[2.5]" />
              <span className="text-xl font-bold tracking-tight">MensaPay</span>
            </div>
            <nav className="hidden md:flex gap-1">
              <button onClick={() => setActiveTab('dashboard')} className={`px-4 py-2 rounded-lg font-medium transition-colors flex items-center gap-2 ${activeTab === 'dashboard' ? 'bg-blue-50 text-blue-600' : 'text-slate-600 hover:bg-slate-50'}`}>
                <Wallet size={18} /> Übersicht
              </button>
              <button onClick={() => setActiveTab('abos')} className={`px-4 py-2 rounded-lg font-medium transition-colors flex items-center gap-2 ${activeTab === 'abos' ? 'bg-blue-50 text-blue-600' : 'text-slate-600 hover:bg-slate-50'}`}>
                <CalendarDays size={18} /> Abos
              </button>
              <button onClick={() => setActiveTab('karten')} className={`px-4 py-2 rounded-lg font-medium transition-colors flex items-center gap-2 ${activeTab === 'karten' ? 'bg-blue-50 text-blue-600' : 'text-slate-600 hover:bg-slate-50'}`}>
                <CreditCard size={18} /> Karten
              </button>
            </nav>
            <div className="flex items-center gap-4">
              <div className="hidden md:flex flex-col items-end">
                <span className="text-sm font-semibold">{user.firstName} {user.lastName}</span>
                <span className="text-xs text-slate-500">Eltern-Account</span>
              </div>
              <button onClick={handleLogout} className="text-slate-400 hover:text-red-500 transition-colors" title="Ausloggen">
                <LogOut size={20} />
              </button>
            </div>
          </div>
        </header>

        <main className="max-w-5xl mx-auto p-4 py-8 flex-1 w-full">
          
          {/* TAB: DASHBOARD */}
          {activeTab === 'dashboard' && (
            <div className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
              <h2 className="text-2xl font-bold text-slate-800">Kontoübersicht</h2>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div className="md:col-span-2 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-3xl p-8 text-white shadow-xl shadow-blue-900/10 relative overflow-hidden">
                  <div className="absolute top-0 right-0 p-8 opacity-10">
                    <Wallet size={120} />
                  </div>
                  <div className="relative z-10">
                    <p className="text-blue-100 font-medium mb-1">Aktuelles Familienguthaben</p>
                    <h3 className="text-5xl font-bold mb-6">{user.balance.toFixed(2).replace('.', ',')} €</h3>
                    <button 
                      onClick={openTopUpModal}
                      className="bg-white text-blue-600 hover:bg-blue-50 px-6 py-3 rounded-xl font-semibold flex items-center gap-2 transition-colors shadow-sm"
                    >
                      <Plus size={20} /> Guthaben aufladen
                    </button>
                  </div>
                </div>
                <div className="bg-white rounded-3xl p-6 border border-slate-100 shadow-sm flex flex-col justify-center">
                  <h4 className="font-semibold text-slate-700 mb-4 flex items-center gap-2">
                    <ShieldCheck size={20} className="text-green-500"/> Kontostatus
                  </h4>
                  <ul className="space-y-3 text-sm text-slate-600">
                    <li className="flex justify-between border-b border-slate-50 pb-2">
                      <span>Verwaltete Schüler</span>
                      <span className="font-bold text-slate-800">{cards.length}</span>
                    </li>
                    <li className="flex justify-between border-b border-slate-50 pb-2">
                      <span>Aktive Abos</span>
                      <span className="font-bold text-slate-800">{abos.filter(a => a.isActive).length}</span>
                    </li>
                    <li className="flex justify-between">
                      <span>Aktive Karten</span>
                      <span className="font-bold text-slate-800">{cards.filter(c => c.status === 'Aktiv').length}</span>
                    </li>
                  </ul>
                </div>
              </div>
              <div className="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden mt-8">
                <div className="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                  <h3 className="font-bold text-slate-800 flex items-center gap-2">
                    <History size={20} className="text-slate-400" /> Vergangene Transaktionen
                  </h3>
                </div>
                <div className="divide-y divide-slate-100">
                  {transactions.length === 0 ? (
                    <div className="p-6 text-center text-slate-500">Bisher keine Transaktionen vorhanden.</div>
                  ) : (
                    <>
                      {transactions.slice(0, visibleTransactions).map(tx => (
                        <div key={tx.id} className="p-4 sm:p-6 flex items-center justify-between hover:bg-slate-50 transition-colors animate-in fade-in duration-300">
                          <div className="flex items-center gap-4">
                            <div className={`p-3 rounded-full ${tx.type === 'deposit' ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-500'}`}>
                              <tx.icon size={20} />
                            </div>
                            <div>
                              <p className="font-semibold text-slate-800">{tx.description}</p>
                              <p className="text-xs text-slate-500">{tx.date}</p>
                            </div>
                          </div>
                          <div className={`font-bold text-lg ${tx.type === 'deposit' ? 'text-green-600' : 'text-slate-800'}`}>
                            {tx.amount > 0 ? '+' : ''}{tx.amount.toFixed(2).replace('.', ',')} €
                          </div>
                        </div>
                      ))}
                      {visibleTransactions < transactions.length && (
                        <div className="p-4 bg-slate-50 flex justify-center border-t border-slate-100">
                          <button 
                            onClick={() => setVisibleTransactions(prev => prev + 10)}
                            className="px-6 py-2.5 bg-white border border-slate-200 hover:border-blue-300 text-blue-600 font-semibold rounded-xl text-sm transition-all shadow-sm flex items-center gap-2 hover:bg-blue-50"
                          >
                            Weitere laden ({transactions.length - visibleTransactions} ältere)
                          </button>
                        </div>
                      )}
                    </>
                  )}
                </div>
              </div>
            </div>
          )}

          {activeTab === 'abos' && (
            <div className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
              <div className="flex justify-between items-end">
                <div>
                  <h2 className="text-2xl font-bold text-slate-800">Mensabos verwalten</h2>
                  <p className="text-slate-500 mt-1">Günstigeres Essen an festen Tagen.</p>
                </div>
                <button onClick={openAboShop} className="hidden sm:flex bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl font-semibold items-center gap-2 transition-colors shadow-sm">
                  <Plus size={20} /> Neues Abo kaufen
                </button>
              </div>
              {abos.filter(a => a.isActive).length === 0 ? (
                <div className="p-8 text-center bg-slate-50 rounded-3xl border-2 border-dashed border-slate-200">
                  <p className="text-slate-500">Keine aktiven Abos vorhanden.</p>
                </div>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  {abos.filter(a => a.isActive).map(renderAboCard)}
                </div>
              )}
              {abos.filter(a => !a.isActive).length > 0 && (
                <div className="mt-8 pt-6 border-t border-slate-200">
                  <button 
                    onClick={() => setShowExpiredAbos(!showExpiredAbos)}
                    className="text-slate-500 hover:text-slate-800 font-medium flex items-center justify-center gap-2 w-full transition-colors"
                  >
                    {showExpiredAbos ? <ChevronLeft size={20} className="-rotate-90" /> : <ChevronRight size={20} className="rotate-90" />}
                    {showExpiredAbos ? 'Abgelaufene Abos ausblenden' : `Abgelaufene Abos anzeigen (${abos.filter(a => !a.isActive).length})`}
                  </button>
                  {showExpiredAbos && (
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6 opacity-80 animate-in fade-in slide-in-from-top-4 duration-300">
                      {abos.filter(a => !a.isActive).map(renderAboCard)}
                    </div>
                  )}
                </div>
              )}
              <button onClick={openAboShop} className="w-full sm:hidden bg-blue-600 text-white px-5 py-4 rounded-xl font-bold flex items-center justify-center gap-2 shadow-sm">
                <Plus size={20} /> Neues Abo kaufen
              </button>
            </div>
          )}

          {/* TAB: KARTEN */}
          {activeTab === 'karten' && (
            <div className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
              <div className="flex justify-between items-end">
                <div>
                  <h2 className="text-2xl font-bold text-slate-800">Chipkarten</h2>
                  <p className="text-slate-500 mt-1">Karten für das Terminal in der Mensa.</p>
                </div>
                <button onClick={openOrderCardModal} className="hidden sm:flex bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 px-5 py-2.5 rounded-xl font-semibold items-center gap-2 transition-colors shadow-sm">
                  <CreditCard size={20} /> Prepaid-Karte bestellen
                </button>
              </div>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {cards.map((card, index) => (
                  <div key={card.holderId || index} className={`bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden ${card.id === 'Wartend...' ? 'opacity-70' : card.status === 'Gesperrt' ? 'opacity-60 grayscale' : ''}`}>
                    <div className="h-24 bg-gradient-to-r from-slate-800 to-slate-700 relative flex items-center px-6">
                      <CreditCard className="text-white/20 absolute right-4 w-16 h-16" />
                      <span className="text-white font-mono tracking-widest text-lg z-10">{card.id}</span>
                    </div>
                    <div className="p-6 relative">
                      <div className="absolute -top-10 right-6 w-16 h-16 bg-white rounded-full p-1 shadow-sm border border-slate-100">
                        <img src={card.img} alt={card.student} className="w-full h-full rounded-full bg-slate-100" />
                      </div>
                      <h3 className="font-bold text-slate-800 text-lg mb-1 mt-2">{card.student}</h3>
                      <div className="flex items-center gap-2 mb-4">
                        <span className={`flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded-md ${card.id === 'Wartend...' ? 'text-amber-600 bg-amber-50' : card.status === 'Gesperrt' ? 'text-red-600 bg-red-50' : 'text-green-600 bg-green-50'}`}>
                          {card.id !== 'Wartend...' && card.status !== 'Gesperrt' && <CheckCircle2 size={12} />} 
                          {card.status === 'Gesperrt' && <XCircle size={12} />}
                          {card.status}
                        </span>
                        {card.isPrepaidOnly ? (
                          <span className="text-xs font-semibold text-blue-600 bg-blue-50 px-2 py-1 rounded-md">Nur Prepaid</span>
                        ) : (
                          <span className="text-xs font-semibold text-purple-600 bg-purple-50 px-2 py-1 rounded-md">Abo verknüpft</span>
                        )}
                      </div>
                      <div className="pt-4 border-t border-slate-100 flex gap-2">
                        {card.id !== 'Wartend...' ? (
                          <>
                            <button disabled={card.status === 'Gesperrt'} onClick={() => openBlockCardModal(card)} className="flex-1 py-2 text-sm font-medium text-slate-600 bg-slate-50 rounded-lg hover:bg-slate-100 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                              {card.status === 'Gesperrt' ? 'Gesperrt' : 'Sperren'}
                            </button>
                            <button onClick={() => openLostCardModal(card)} className="flex-1 py-2 text-sm font-medium text-slate-600 bg-slate-50 rounded-lg hover:bg-slate-100 transition-colors">
                              Karte verloren
                            </button>
                          </>
                        ) : (
                          <p className="text-xs text-slate-500 w-full text-center py-1">Bitte an Lehrkraft wenden, um die physische Karte zu erhalten.</p>
                        )}
                      </div>
                    </div>
                  </div>
                ))}
                <div className="bg-slate-50 rounded-3xl border-2 border-dashed border-slate-200 flex flex-col items-center justify-center p-8 text-center min-h-[250px]">
                  <div className="w-16 h-16 bg-white rounded-full flex items-center justify-center text-blue-500 shadow-sm mb-4">
                    <Plus size={32} />
                  </div>
                  <h3 className="font-bold text-slate-700 mb-2">Weitere Karte benötigt?</h3>
                  <p className="text-sm text-slate-500 mb-6">Bestelle eine reine Prepaid-Karte für einen weiteren Schüler.</p>
                  <button onClick={openOrderCardModal} className="py-2.5 px-6 bg-white border border-slate-300 rounded-xl font-semibold text-slate-700 hover:bg-slate-100 transition-colors shadow-sm">
                    Jetzt bestellen
                  </button>
                  <p className="text-xs text-slate-400 mt-4">Bei Abos ist die Karte inkl.</p>
                </div>
              </div>
            </div>
          )}

          {/* TAB: ABO-SHOP WIZARD */}
          {activeTab === 'abo-shop' && (
            <div className="max-w-3xl mx-auto space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
              <div className="flex items-center gap-4 mb-8">
                <button onClick={() => setActiveTab('abos')} className="p-2 bg-white rounded-full shadow-sm border border-slate-200 hover:bg-slate-50 transition-colors">
                  <ChevronLeft size={24} className="text-slate-600" />
                </button>
                <div>
                  <h2 className="text-2xl font-bold text-slate-800">Abo konfigurieren</h2>
                  <p className="text-slate-500 mt-1">Schritt {aboStep} von 4</p>
                </div>
              </div>
              <div className="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 sm:p-8">
                
                {/* Step 1: Abo Typ */}
                {aboStep === 1 && (
                  <div className="space-y-6 animate-in fade-in duration-300">
                    <h3 className="text-xl font-bold text-slate-800 mb-4">1. Laufzeit wählen</h3>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                      <button 
                        onClick={() => setShopData({...shopData, type: 'halb'})}
                        className={`p-6 rounded-2xl border-2 text-left transition-all ${shopData.type === 'halb' ? 'border-blue-500 bg-blue-50 ring-4 ring-blue-500/10' : 'border-slate-200 hover:border-blue-300'}`}
                      >
                        <h4 className="text-lg font-bold text-slate-800">Halbjahresabo</h4>
                        <p className="text-sm text-slate-500 mt-2 mb-4">Gültig für 1 Schulhalbjahr. Ideal zum Ausprobieren.</p>
                        <p className="font-semibold text-blue-600">{sysPrices.halfYear.toFixed(2).replace('.', ',')} € pro Wochentag</p>
                      </button>
                      <button 
                        onClick={() => setShopData({...shopData, type: 'ganz'})}
                        className={`p-6 rounded-2xl border-2 text-left transition-all ${shopData.type === 'ganz' ? 'border-blue-500 bg-blue-50 ring-4 ring-blue-500/10' : 'border-slate-200 hover:border-blue-300'}`}
                      >
                        <h4 className="text-lg font-bold text-slate-800">Ganzjahresabo</h4>
                        <p className="text-sm text-slate-500 mt-2 mb-4">Gültig für das gesamte Schuljahr. Der beste Deal.</p>
                        <p className="font-semibold text-blue-600">{sysPrices.fullYear.toFixed(2).replace('.', ',')} € pro Wochentag</p>
                      </button>
                    </div>
                    <div className="mt-8 flex justify-end">
                      <button 
                        disabled={!shopData.type}
                        onClick={() => setAboStep(2)} 
                        className="bg-blue-600 hover:bg-blue-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white px-6 py-3 rounded-xl font-semibold flex items-center gap-2 transition-colors"
                      >
                        Weiter <ChevronRight size={20} />
                      </button>
                    </div>
                  </div>
                )}

                {/* Step 2: Wochentage */}
                {aboStep === 2 && (
                  <div className="space-y-6 animate-in fade-in duration-300">
                    <h3 className="text-xl font-bold text-slate-800 mb-2">2. Wochentage auswählen</h3>
                    <p className="text-slate-600 mb-6">An welchen Tagen soll das Essen inkludiert sein? Der Preis wird pro ausgewähltem Wochentag berechnet ({shopData.type === 'halb' ? sysPrices.halfYear.toFixed(2).replace('.', ',') : sysPrices.fullYear.toFixed(2).replace('.', ',')} € je Tag).</p>
                    
                    <div className="flex flex-wrap gap-3">
                      {['Mo', 'Di', 'Mi', 'Do', 'Fr'].map(day => {
                        const isSelected = shopData.days.includes(day);
                        return (
                          <button 
                            key={day}
                            onClick={() => {
                              if (isSelected) {
                                setShopData({...shopData, days: shopData.days.filter(d => d !== day)});
                              } else {
                                setShopData({...shopData, days: [...shopData.days, day]});
                              }
                            }}
                            className={`w-16 h-16 sm:w-20 sm:h-20 rounded-2xl font-bold text-lg transition-all ${isSelected ? 'bg-blue-600 text-white shadow-md shadow-blue-600/30 scale-105 border-2 border-blue-600' : 'bg-slate-100 text-slate-500 border-2 border-transparent hover:bg-slate-200'}`}
                          >
                            {day}
                          </button>
                        );
                      })}
                    </div>
                    <div className="mt-8 flex justify-between items-center border-t border-slate-100 pt-6">
                      <button onClick={() => setAboStep(1)} className="text-slate-500 font-semibold px-4 py-2 hover:bg-slate-100 rounded-lg transition-colors">Zurück</button>
                      <button 
                        disabled={shopData.days.length === 0}
                        onClick={() => setAboStep(3)} 
                        className="bg-blue-600 hover:bg-blue-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white px-6 py-3 rounded-xl font-semibold flex items-center gap-2 transition-colors"
                      >
                        Weiter <ChevronRight size={20} />
                      </button>
                    </div>
                  </div>
                )}

                {/* Step 3: Kartenzuweisung */}
                {aboStep === 3 && (
                  <div className="space-y-6 animate-in fade-in duration-300">
                    <h3 className="text-xl font-bold text-slate-800 mb-4">3. Schüler Profil</h3>
                    <div className="space-y-4">
                      <label className={`block p-4 border-2 rounded-xl cursor-pointer transition-all ${shopData.cardOption === 'existing' ? 'border-blue-500 bg-blue-50' : 'border-slate-200 hover:border-blue-300'}`}>
                        <div className="flex items-center gap-3">
                          <input type="radio" checked={shopData.cardOption === 'existing'} onChange={() => setShopData({...shopData, cardOption: 'existing'})} className="w-5 h-5 text-blue-600" />
                          <span className="font-bold text-slate-800">Bestehendes Profil nutzen</span>
                        </div>
                        {shopData.cardOption === 'existing' && (
                          <div className="mt-4 pl-8">
                            {cards.length > 0 ? (
                              <select 
                                value={shopData.selectedHolderId}
                                onChange={(e) => setShopData({...shopData, selectedHolderId: e.target.value})}
                                className="w-full p-3 rounded-xl border border-blue-200 bg-white outline-none focus:ring-2 focus:ring-blue-500"
                              >
                                {cards.map(c => (
                                  <option key={c.holderId} value={c.holderId}>{c.student} ({c.id === 'Wartend...' ? 'Ausstehende Karte' : 'Karte: ' + c.id})</option>
                                ))}
                              </select>
                            ) : (
                              <p className="text-sm text-red-500">Keine Profile vorhanden. Bitte lege ein neues an.</p>
                            )}
                          </div>
                        )}
                      </label>
                      <label className={`block p-4 border-2 rounded-xl cursor-pointer transition-all ${shopData.cardOption === 'new' ? 'border-blue-500 bg-blue-50' : 'border-slate-200 hover:border-blue-300'}`}>
                        <div className="flex items-center gap-3">
                          <input type="radio" checked={shopData.cardOption === 'new'} onChange={() => setShopData({...shopData, cardOption: 'new'})} className="w-5 h-5 text-blue-600" />
                          <div>
                            <span className="font-bold text-slate-800 block">Neues Schüler-Profil anlegen</span>
                            <span className="text-xs text-slate-500">+ {sysPrices.cardDeposit.toFixed(2).replace('.', ',')} € Kartenpfand</span>
                          </div>
                        </div>
                        {shopData.cardOption === 'new' && (
                          <div className="mt-4 pl-8 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                              <label className="text-xs font-semibold text-slate-600 mb-1 block">Vorname</label>
                              <input type="text" value={shopData.newStudent.firstName} onChange={e => setShopData({...shopData, newStudent: {...shopData.newStudent, firstName: e.target.value}})} className="w-full p-2.5 rounded-lg border border-blue-200 outline-none focus:ring-2 focus:ring-blue-500" placeholder="Max" />
                            </div>
                            <div>
                              <label className="text-xs font-semibold text-slate-600 mb-1 block">Nachname</label>
                              <input type="text" value={shopData.newStudent.lastName} onChange={e => setShopData({...shopData, newStudent: {...shopData.newStudent, lastName: e.target.value}})} className="w-full p-2.5 rounded-lg border border-blue-200 outline-none focus:ring-2 focus:ring-blue-500" placeholder="Mustermann" />
                            </div>
                            <div className="sm:col-span-2">
                              <label className="text-xs font-semibold text-slate-600 mb-1 block">Klasse</label>
                              <select value={shopData.newStudent.class} onChange={e => setShopData({...shopData, newStudent: {...shopData.newStudent, class: e.target.value}})} className="w-full p-2.5 rounded-lg border border-blue-200 outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                                <option value="">Bitte wählen...</option>
                                {['5a','5b','5c','5d','6a','6b','6c','6d','7a','7b','7c','7d','8a','8b','8c','8d','9a','9b','9c','9d','10a','10b','10c','10d','11a','11b','11c','11d','Q12','Q13'].map(cls => (
                                  <option key={cls} value={cls}>Klasse {cls}</option>
                                ))}
                              </select>
                            </div>
                          </div>
                        )}
                      </label>
                    </div>
                    <div className="mt-8 flex justify-between items-center border-t border-slate-100 pt-6">
                      <button onClick={() => setAboStep(2)} className="text-slate-500 font-semibold px-4 py-2 hover:bg-slate-100 rounded-lg transition-colors">Zurück</button>
                      <button 
                        disabled={(shopData.cardOption === 'existing' && (!shopData.selectedHolderId || cards.length === 0)) || (shopData.cardOption === 'new' && (!shopData.newStudent.firstName || !shopData.newStudent.lastName || !shopData.newStudent.class))}
                        onClick={() => setAboStep(4)} 
                        className="bg-blue-600 hover:bg-blue-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white px-6 py-3 rounded-xl font-semibold flex items-center gap-2 transition-colors"
                      >
                        Weiter zur Kasse <ChevronRight size={20} />
                      </button>
                    </div>
                  </div>
                )}

                {/* Step 4: Checkout */}
                {aboStep === 4 && (
                  <div className="space-y-6 animate-in fade-in duration-300">
                    <h3 className="text-xl font-bold text-slate-800 mb-4">4. Zusammenfassung & Zahlung</h3>
                    <div className="bg-slate-50 rounded-2xl p-6 border border-slate-100 mb-6">
                      <div className="flex justify-between items-center mb-4 pb-4 border-b border-slate-200">
                        <div>
                          <p className="font-bold text-slate-800">Wochentage ({shopData.days.length}x)</p>
                          <p className="text-sm text-slate-500">{shopData.days.join(', ')} á {shopData.type === 'halb' ? sysPrices.halfYear.toFixed(2).replace('.', ',') : sysPrices.fullYear.toFixed(2).replace('.', ',')} €</p>
                        </div>
                        <span className="font-semibold">{(shopData.days.length * (shopData.type === 'halb' ? sysPrices.halfYear : sysPrices.fullYear)).toFixed(2).replace('.', ',')} €</span>
                      </div>
                      {shopData.cardOption === 'new' && (
                        <div className="flex justify-between items-center mb-4 pb-4 border-b border-slate-200">
                          <div>
                            <p className="font-bold text-slate-800">Neues Profil ({shopData.newStudent.firstName} {shopData.newStudent.lastName})</p>
                            <p className="text-sm text-slate-500">inkl. Kartenpfand</p>
                          </div>
                          <span className="font-semibold">{sysPrices.cardDeposit.toFixed(2).replace('.', ',')} €</span>
                        </div>
                      )}
                      <div className="flex justify-between items-center text-lg mt-2">
                        <p className="font-bold text-slate-800">Gesamtbetrag</p>
                        <span className="font-bold text-slate-800">{calculateAboPrice().toFixed(2).replace('.', ',')} €</span>
                      </div>
                      {user.balance > 0 && (
                        <div className="mt-4 pt-4 border-t border-slate-200">
                          <label className="flex items-center gap-3 cursor-pointer">
                            <input 
                              type="checkbox" 
                              checked={shopData.useBalance} 
                              onChange={(e) => setShopData({...shopData, useBalance: e.target.checked})}
                              className="w-5 h-5 text-blue-600 rounded border-slate-300 focus:ring-blue-500"
                            />
                            <div>
                              <p className="font-semibold text-slate-800">Guthaben ({user.balance.toFixed(2).replace('.', ',')} €) verrechnen</p>
                              <p className="text-sm text-slate-500">Wird vom Gesamtbetrag abgezogen</p>
                            </div>
                          </label>
                        </div>
                      )}
                      {shopData.useBalance && user.balance > 0 && (
                        <div className="flex justify-between items-center text-lg mt-4 pt-4 border-t border-slate-200">
                          <p className="font-bold text-slate-800">Noch zu zahlen</p>
                          <span className="font-bold text-blue-600">
                            {Math.max(0, calculateAboPrice() - user.balance).toFixed(2).replace('.', ',')} €
                          </span>
                        </div>
                      )}
                    </div>
                    {Math.max(0, calculateAboPrice() - (shopData.useBalance ? user.balance : 0)) > 0 && (
                      <div className="mb-6">
                        <p className="font-semibold text-slate-800 text-sm mb-2">Zahlungsmethode wählen</p>
                        <div className="space-y-2">
                          {[
                            { id: 'paypal', name: 'PayPal', icon: Smartphone },
                            { id: 'klarna', name: 'Klarna', icon: ShoppingCart },
                            { id: 'ueberweisung', name: 'Banküberweisung', icon: Landmark }
                          ].map(method => (
                            <label key={method.id} className={`flex items-center gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all ${aboPayment === method.id ? 'border-blue-500 bg-blue-50' : 'border-slate-200 hover:border-blue-300'}`}>
                              <input type="radio" name="aboPayment" checked={aboPayment === method.id} onChange={() => setAboPayment(method.id)} className="w-5 h-5 text-blue-600" />
                              <method.icon size={20} className={aboPayment === method.id ? 'text-blue-600' : 'text-slate-500'} />
                              <span className="font-bold text-slate-800">{method.name}</span>
                            </label>
                          ))}
                        </div>
                      </div>
                    )}
                    <div className="space-y-3">
                      <CheckoutAction 
                        amount={Math.max(0, calculateAboPrice() - (shopData.useBalance ? user.balance : 0))}
                        paymentMethod={aboPayment}
                        actionType="buy_abo"
                        actionData={{
                          type: shopData.type,
                          days: shopData.days,
                          cardOption: shopData.cardOption,
                          selectedHolderId: shopData.selectedHolderId,
                          newStudent: shopData.newStudent,
                          useBalance: shopData.useBalance
                        }}
                        onManualSubmit={(method) => handleManualBuyAbo(method)}
                        onSucceed={() => {
                          if (shopData.cardOption === 'new') {
                            setAboStep(5);
                            fetchUserData(false);
                          } else {
                            showToast("Abo erfolgreich gebucht!");
                            setActiveTab('abos');
                            fetchUserData(false);
                          }
                        }}
                        onProcessing={() => setIsActionLoading(true)}
                        onFinished={() => setIsActionLoading(false)}
                        isLoading={isActionLoading}
                        user={user}
                        config={sysPrices}
                      />
                    </div>
                    <div className="mt-8 pt-6 border-t border-slate-100">
                      <button onClick={() => setAboStep(3)} className="text-slate-500 font-semibold px-4 py-2 hover:bg-slate-100 rounded-lg transition-colors">Zurück</button>
                    </div>
                  </div>
                )}

                {/* Step 5: SUCCESS SCREEN FÜR NEUE PROFILE IM ABO-SHOP */}
                {aboStep === 5 && (
                  <div className="text-center space-y-4 py-8 animate-in fade-in duration-300">
                    <div className="w-20 h-20 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                      <CheckCircle2 size={40} />
                    </div>
                    <h3 className="text-2xl font-bold text-slate-800">Abo erfolgreich gebucht!</h3>
                    {aboPayment === 'ueberweisung' && paymentPin && (
                      <div className="bg-amber-50 text-amber-900 p-5 rounded-xl text-sm border border-amber-200 text-left mb-6">
                        <p className="font-bold mb-3">Zahlung ausstehend. Bitte überweise den fälligen Betrag an:</p>
                        <div className="space-y-2 font-mono">
                          <p className="flex justify-between"><span>Empfänger:</span> <strong>{sysPrices.schoolName}</strong></p>
                          <p className="flex justify-between"><span>IBAN:</span> <strong>{sysPrices.schoolIban}</strong></p>
                          <p className="flex justify-between"><span>BIC:</span> <strong>{sysPrices.schoolBic}</strong></p>
                        </div>
                        <div className="mt-4 pt-4 border-t border-amber-200">
                          <p className="text-xs text-amber-700 uppercase tracking-wider font-sans font-bold mb-1">Verwendungszweck (SEHR WICHTIG):</p>
                          <p className="font-bold text-2xl bg-white px-3 py-3 rounded-lg border-2 border-amber-300 text-center select-all shadow-sm tracking-widest">
                            MENSA {paymentPin}
                          </p>
                        </div>
                      </div>
                    )}
                    {shopData.cardOption === 'new' && (
                      <div className="bg-blue-50 text-blue-800 p-5 rounded-xl text-left border border-blue-100 text-sm mt-4">
                        <p className="mb-3">Das neue Schülerprofil und das Abo wurden erfolgreich im System registriert.</p>
                        <p className="font-bold flex items-center gap-2"><CreditCard size={18}/> Wichtig für den nächsten Schritt:</p>
                        <p className="mt-1">Die physische Chipkarte muss noch ausgegeben werden. Bitte wende dich (oder der Schüler) an eine <strong>Lehrkraft in der Schule</strong>, um eine freie Karte zu erhalten. Erst danach ist die Karte vollständig in der Verwaltung sichtbar und für das Essen nutzbar.</p>
                      </div>
                    )}
                    <button 
                      onClick={() => {
                        setActiveTab('abos');
                      }}
                      className="w-full mt-6 py-4 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold shadow-sm transition-colors"
                    >
                      Verstanden, zur Übersicht
                    </button>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* TAB: LEGAL (Impressum & Datenschutz im eingeloggten Bereich) */}
          {(activeTab === 'impressum' || activeTab === 'datenschutz') && (
            <div className="max-w-3xl mx-auto space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
              <LegalText type={activeTab} htmlContent={legalContent[activeTab]} isLoading={isLegalLoading} />
            </div>
          )}
        </main>

        <footer className="w-full max-w-5xl mx-auto p-4 text-center text-sm text-slate-500 hidden md:block">
          <button onClick={() => setActiveTab('impressum')} className="hover:text-blue-600 transition-colors mx-3">Impressum</button>
          |
          <button onClick={() => setActiveTab('datenschutz')} className="hover:text-blue-600 transition-colors mx-3">Datenschutz</button>
        </footer>

        <nav className="md:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-slate-200 pb-safe z-40">
          <div className="flex justify-around items-center h-16">
            <button onClick={() => setActiveTab('dashboard')} className={`flex flex-col items-center justify-center w-full h-full space-y-1 ${activeTab === 'dashboard' ? 'text-blue-600' : 'text-slate-500'}`}>
              <Wallet size={20} className={activeTab === 'dashboard' ? 'fill-blue-50' : ''} />
              <span className="text-[10px] font-medium">Übersicht</span>
            </button>
            <button onClick={() => setActiveTab('abos')} className={`flex flex-col items-center justify-center w-full h-full space-y-1 ${activeTab === 'abos' ? 'text-blue-600' : 'text-slate-500'}`}>
              <CalendarDays size={20} className={activeTab === 'abos' ? 'fill-blue-50' : ''} />
              <span className="text-[10px] font-medium">Abos</span>
            </button>
            <button onClick={() => setActiveTab('karten')} className={`flex flex-col items-center justify-center w-full h-full space-y-1 ${activeTab === 'karten' ? 'text-blue-600' : 'text-slate-500'}`}>
              <CreditCard size={20} className={activeTab === 'karten' ? 'fill-blue-50' : ''} />
              <span className="text-[10px] font-medium">Karten</span>
            </button>
            
            {/* Mobile Footer Links direkt im Menü */}
            <div className="hidden">
              <button onClick={() => setActiveTab('impressum')} />
              <button onClick={() => setActiveTab('datenschutz')} />
            </div>
          </div>
        </nav>

        {/* Top-Up Modal */}
        <Modal isOpen={isTopUpOpen} onClose={() => setIsTopUpOpen(false)} title="Guthaben aufladen">
          {topUpStep === 'choose' && (
            <div className="space-y-4">
              <p className="text-sm text-slate-600 mb-4">Wähle eine Methode, um das Prepaid-Guthaben für Käufe in der Mensa aufzuladen.</p>
              
              <button onClick={() => setTopUpStep('online')} className="w-full flex items-center justify-between p-4 border border-slate-200 rounded-xl hover:border-blue-500 hover:bg-blue-50 transition-all group">
                <div className="flex items-center gap-3">
                  <div className="bg-blue-100 p-2 rounded-lg text-blue-600 group-hover:bg-blue-200"><Smartphone size={24} /></div>
                  <div className="text-left">
                    <p className="font-bold text-slate-800">Online aufladen</p>
                    <p className="text-xs text-slate-500">Klarna, PayPal, Kreditkarte</p>
                  </div>
                </div>
                <ChevronRight className="text-slate-400 group-hover:text-blue-600" />
              </button>
              <button onClick={() => setTopUpStep('bar')} className="w-full flex items-center justify-between p-4 border border-slate-200 rounded-xl hover:border-green-500 hover:bg-green-50 transition-all group">
                <div className="flex items-center gap-3">
                  <div className="bg-green-100 p-2 rounded-lg text-green-600 group-hover:bg-green-200"><Banknote size={24} /></div>
                  <div className="text-left">
                    <p className="font-bold text-slate-800">Vor Ort Bar aufladen</p>
                    <p className="text-xs text-slate-500">In der Finanzstelle der Schule</p>
                  </div>
                </div>
                <ChevronRight className="text-slate-400 group-hover:text-green-600" />
              </button>
            </div>
          )}
          {topUpStep === 'online' && (
            <div className="space-y-4 animate-in fade-in slide-in-from-right-4 duration-300">
              <button onClick={() => setTopUpStep('choose')} className="flex items-center gap-2 text-sm text-slate-500 hover:text-slate-800 mb-2">
                <ChevronLeft size={16}/> Zurück
              </button>
              <label className="block text-sm font-medium text-slate-700 mb-1">Betrag in Euro</label>
              <input 
                type="number" 
                min="5" 
                step="5" 
                value={topUpAmount} 
                onChange={e => setTopUpAmount(e.target.value)} 
                className="w-full px-4 py-3 rounded-xl border border-slate-200 text-xl font-bold outline-none focus:ring-2 focus:ring-blue-500" 
              />
              <div className="flex gap-2 mt-2">
                {[10, 20, 50].map(amt => (
                  <button key={amt} onClick={() => setTopUpAmount(amt)} className={`flex-1 py-2 rounded-lg font-semibold transition-colors ${Number(topUpAmount) === amt ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>+{amt}€</button>
                ))}
              </div>
              <button 
                disabled={!topUpAmount || topUpAmount <= 0}
                onClick={() => setTopUpStep('checkout')} 
                className="w-full mt-6 py-3 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white rounded-xl font-bold shadow-sm"
              >
                Weiter zur Zahlung
              </button>
            </div>
          )}
          {topUpStep === 'checkout' && (
            <div className="space-y-4 animate-in fade-in slide-in-from-right-4 duration-300">
              <button onClick={() => setTopUpStep('online')} className="flex items-center gap-2 text-sm text-slate-500 hover:text-slate-800 mb-4">
                <ChevronLeft size={16}/> Zurück
              </button>
              <div className="bg-slate-50 rounded-2xl p-6 border border-slate-100 mb-6">
                <div className="flex justify-between items-center text-lg">
                  <p className="font-bold text-slate-800">Aufladebetrag</p>
                  <span className="font-bold text-blue-600">{Number(topUpAmount).toFixed(2).replace('.', ',')} €</span>
                </div>
              </div>

              <p className="font-semibold text-slate-800 text-sm mb-2">Zahlungsmethode wählen</p>
              <div className="space-y-2 mb-6">
                {[
                  { id: 'paypal', name: 'PayPal', icon: Smartphone },
                  { id: 'klarna', name: 'Klarna', icon: ShoppingCart },
                  { id: 'ueberweisung', name: 'Banküberweisung', icon: Landmark }
                ].map(method => (
                  <label key={method.id} className={`flex items-center gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all ${topUpPayment === method.id ? 'border-blue-500 bg-blue-50' : 'border-slate-200 hover:border-blue-300'}`}>
                    <input type="radio" name="topUpPayment" checked={topUpPayment === method.id} onChange={() => setTopUpPayment(method.id)} className="w-5 h-5 text-blue-600" />
                    <method.icon size={20} className={topUpPayment === method.id ? 'text-blue-600' : 'text-slate-500'} />
                    <span className="font-bold text-slate-800">{method.name}</span>
                  </label>
                ))}
              </div>
              <CheckoutAction 
                amount={Number(topUpAmount)}
                paymentMethod={topUpPayment}
                actionType="topup"
                actionData={{ amount: Number(topUpAmount) }}
                onManualSubmit={(method) => handleManualTopUp(method)}
                onSucceed={() => {
                  showToast("Aufladung erfolgreich!");
                  setIsTopUpOpen(false);
                  fetchUserData(false);
                }}
                onProcessing={() => setIsActionLoading(true)}
                onFinished={() => setIsActionLoading(false)}
                isLoading={isActionLoading}
                user={user}
                config={sysPrices}
              />
            </div>
          )}
          {topUpStep === 'bar' && (
            <div className="space-y-4 animate-in fade-in slide-in-from-right-4 duration-300">
              <button onClick={() => setTopUpStep('choose')} className="flex items-center gap-2 text-sm text-slate-500 hover:text-slate-800 mb-2">
                <ChevronLeft size={16}/> Zurück
              </button>
              <div className="bg-green-50 text-green-800 p-5 rounded-xl border border-green-200">
                <h4 className="font-bold mb-2 flex items-center gap-2"><Banknote size={20}/> Anleitung Barzahlung</h4>
                <p className="text-sm mb-4">Du kannst dein Guthaben jederzeit vor Ort in der Schule aufladen. Gehe dazu einfach zur Finanzstelle neben dem Sekretariat.</p>
                <p className="text-sm font-semibold mb-2">Nenne dort diese Daten:</p>
                <ul className="list-disc list-inside text-sm space-y-2 mb-4">
                  <li>Deine E-Mail: <strong>{user.email}</strong></li>
                  <li>Oder bringe eine bestehende Mensakarte mit.</li>
                </ul>
                <p className="text-sm text-green-700">Das Guthaben ist nach der Einzahlung sofort in deinem Account verfügbar.</p>
              </div>
              <button onClick={() => setIsTopUpOpen(false)} className="w-full mt-4 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold transition-colors">
                Verstanden
              </button>
            </div>
          )}
          {topUpStep === 'success-ueberweisung' && (
            <div className="text-center space-y-4 py-4 animate-in fade-in duration-300">
              <div className="w-20 h-20 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mx-auto mb-4 shadow-inner">
                <Landmark size={40} />
              </div>
              <h3 className="text-2xl font-bold text-slate-800">Überweisung ausstehend</h3>
              <div className="bg-blue-50 text-blue-900 p-5 rounded-xl text-sm border border-blue-200 text-left">
                <p className="font-bold mb-3">Bitte überweise den Betrag ({Number(topUpAmount).toFixed(2).replace('.', ',')} €) an:</p>
                <div className="space-y-2 font-mono">
                  <p className="flex justify-between"><span>Empfänger:</span> <strong>{sysPrices.schoolName}</strong></p>
                  <p className="flex justify-between"><span>IBAN:</span> <strong>{sysPrices.schoolIban}</strong></p>
                  <p className="flex justify-between"><span>BIC:</span> <strong>{sysPrices.schoolBic}</strong></p>
                </div>
                <div className="mt-4 pt-4 border-t border-blue-200">
                  <p className="text-xs text-blue-700 uppercase tracking-wider font-sans font-bold mb-1">Verwendungszweck (SEHR WICHTIG):</p>
                  <p className="font-bold text-2xl bg-white px-3 py-3 rounded-lg border-2 border-blue-300 text-center select-all shadow-sm tracking-widest">
                    MENSA {paymentPin}
                  </p>
                </div>
              </div>
              <p className="text-xs text-slate-500 mt-4">Dein Guthaben wird automatisch gutgeschrieben, sobald der Betrag mit dem korrekten Verwendungszweck eingegangen ist (Dauer: 1-3 Werktage).</p>
              <button 
                onClick={() => setIsTopUpOpen(false)}
                className="w-full mt-6 py-4 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold shadow-sm transition-colors"
              >
                Verstanden, Fenster schließen
              </button>
            </div>
          )}
        </Modal>

        {/* Prepaid Card Order Modal */}
        <Modal isOpen={isOrderCardOpen} onClose={() => setIsOrderCardOpen(false)} title="Schülerprofil anlegen">
          {orderCardStep === 'form' && (
            <div className="space-y-4 animate-in fade-in duration-300">
              <p className="text-sm text-slate-600 mb-4">Lege ein neues Schülerprofil für die Mensa an. Für die Einrichtung und die spätere Chipkarte fällt ein Pfand von <strong>{sysPrices.cardDeposit.toFixed(2).replace('.', ',')} €</strong> an.</p>
              
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="text-xs font-semibold text-slate-600 mb-1 block">Vorname</label>
                  <input type="text" value={newCardData.firstName} onChange={e => setNewCardData({...newCardData, firstName: e.target.value})} className="w-full p-2.5 rounded-lg border border-slate-200 outline-none focus:ring-2 focus:ring-blue-500" placeholder="Max" />
                </div>
                <div>
                  <label className="text-xs font-semibold text-slate-600 mb-1 block">Nachname</label>
                  <input type="text" value={newCardData.lastName} onChange={e => setNewCardData({...newCardData, lastName: e.target.value})} className="w-full p-2.5 rounded-lg border border-slate-200 outline-none focus:ring-2 focus:ring-blue-500" placeholder="Mustermann" />
                </div>
                <div className="sm:col-span-2">
                  <label className="text-xs font-semibold text-slate-600 mb-1 block">Klasse</label>
                  <select value={newCardData.class} onChange={e => setNewCardData({...newCardData, class: e.target.value})} className="w-full p-2.5 rounded-lg border border-slate-200 outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Bitte wählen...</option>
                    {['5a','5b','5c','5d','6a','6b','6c','6d','7a','7b','7c','7d','8a','8b','8c','8d','9a','9b','9c','9d','10a','10b','10c','10d','11a','11b','11c','11d','Q12','Q13'].map(cls => (
                      <option key={cls} value={cls}>Klasse {cls}</option>
                    ))}
                  </select>
                </div>
              </div>

              {user.balance > 0 && (
                <label className="flex items-center gap-3 cursor-pointer mt-4 p-3 border border-slate-200 rounded-xl bg-slate-50 hover:bg-slate-100 transition-colors">
                  <input 
                    type="checkbox" 
                    checked={newCardData.useBalance} 
                    onChange={(e) => setNewCardData({...newCardData, useBalance: e.target.checked})}
                    className="w-5 h-5 text-blue-600 rounded border-slate-300 focus:ring-blue-500"
                  />
                  <div>
                    <p className="font-semibold text-slate-800 text-sm">Guthaben ({user.balance.toFixed(2).replace('.', ',')} €) nutzen</p>
                    <p className="text-xs text-slate-500">Wird anteilig mit dem Kartenpfand verrechnet</p>
                  </div>
                </label>
              )}

              <button 
                disabled={!newCardData.firstName || !newCardData.lastName || !newCardData.class}
                onClick={() => setOrderCardStep('checkout')}
                className="w-full mt-6 py-3 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white rounded-xl font-bold flex justify-center items-center gap-2 shadow-sm transition-colors"
              >
                Weiter zur Zahlung
              </button>
            </div>
          )}
          {orderCardStep === 'checkout' && (
            <div className="space-y-4 animate-in fade-in slide-in-from-right-4 duration-300">
              <button onClick={() => setOrderCardStep('form')} className="flex items-center gap-2 text-sm text-slate-500 hover:text-slate-800 mb-4">
                <ChevronLeft size={16}/> Zurück
              </button>
              <div className="bg-slate-50 rounded-2xl p-6 border border-slate-100 mb-6">
                <div className="flex justify-between items-center mb-2 pb-2 border-b border-slate-200">
                  <div>
                    <p className="font-bold text-slate-800">Neues Profil</p>
                    <p className="text-sm text-slate-500">{newCardData.firstName} {newCardData.lastName} (Klasse {newCardData.class})</p>
                  </div>
                  <span className="font-semibold">{sysPrices.cardDeposit.toFixed(2).replace('.', ',')} €</span>
                </div>
                <div className="flex justify-between items-center text-lg mt-2">
                  <p className="font-bold text-slate-800">Gesamtbetrag</p>
                  <span className="font-bold text-slate-800">{sysPrices.cardDeposit.toFixed(2).replace('.', ',')} €</span>
                </div>
                
                {newCardData.useBalance && user.balance > 0 && (
                  <>
                    <div className="flex justify-between items-center text-sm mt-2 text-slate-500">
                      <p>Abzüglich Guthaben</p>
                      <span>- {Math.min(sysPrices.cardDeposit, user.balance).toFixed(2).replace('.', ',')} €</span>
                    </div>
                    <div className="flex justify-between items-center text-lg mt-4 pt-4 border-t border-slate-200">
                      <p className="font-bold text-slate-800">Noch zu zahlen</p>
                      <span className="font-bold text-blue-600">{Math.max(0, sysPrices.cardDeposit - user.balance).toFixed(2).replace('.', ',')} €</span>
                    </div>
                  </>
                )}
              </div>

              {Math.max(0, sysPrices.cardDeposit - (newCardData.useBalance ? user.balance : 0)) > 0 && (
                <>
                  <p className="font-semibold text-slate-800 text-sm mb-2">Zahlungsmethode wählen</p>
                  <div className="space-y-2 mb-6">
                    {[
                      { id: 'paypal', name: 'PayPal', icon: Smartphone },
                      { id: 'klarna', name: 'Klarna', icon: ShoppingCart },
                      { id: 'ueberweisung', name: 'Banküberweisung', icon: Landmark }
                    ].map(method => (
                      <label key={method.id} className={`flex items-center gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all ${orderCardPayment === method.id ? 'border-blue-500 bg-blue-50' : 'border-slate-200 hover:border-blue-300'}`}>
                        <input type="radio" name="orderCardPayment" checked={orderCardPayment === method.id} onChange={() => setOrderCardPayment(method.id)} className="w-5 h-5 text-blue-600" />
                        <method.icon size={20} className={orderCardPayment === method.id ? 'text-blue-600' : 'text-slate-500'} />
                        <span className="font-bold text-slate-800">{method.name}</span>
                      </label>
                    ))}
                  </div>
                </>
              )}

              <CheckoutAction 
                amount={Math.max(0, sysPrices.cardDeposit - (newCardData.useBalance ? user.balance : 0))}
                paymentMethod={orderCardPayment}
                actionType="order_card"
                actionData={{
                  firstName: newCardData.firstName,
                  lastName: newCardData.lastName,
                  class: newCardData.class,
                  useBalance: newCardData.useBalance
                }}
                onManualSubmit={(method) => handleManualOrderCard(method)}
                onSucceed={() => {
                  setOrderCardStep('success');
                  fetchUserData(false);
                }}
                onProcessing={() => setIsActionLoading(true)}
                onFinished={() => setIsActionLoading(false)}
                isLoading={isActionLoading}
                user={user}
                config={sysPrices}
              />
            </div>
          )}

          {/* NEUER SUCCESS SCREEN (Zeigt an, wie es nun weitergeht) */}
          {orderCardStep === 'success' && (
            <div className="text-center space-y-4 py-4 animate-in fade-in duration-300">
              <div className="w-20 h-20 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                <CheckCircle2 size={40} />
              </div>
              <h3 className="text-2xl font-bold text-slate-800">Schülerprofil angelegt!</h3>

              {orderCardPayment === 'ueberweisung' && paymentPin && (
                <div className="bg-amber-50 text-amber-900 p-5 rounded-xl text-sm border border-amber-200 text-left mb-6">
                  <p className="font-bold mb-3">Zahlung ausstehend. Bitte überweise den fälligen Betrag an:</p>
                  <div className="space-y-2 font-mono">
                    <p className="flex justify-between"><span>Empfänger:</span> <strong>{sysPrices.schoolName}</strong></p>
                          <p className="flex justify-between"><span>IBAN:</span> <strong>{sysPrices.schoolIban}</strong></p>
                          <p className="flex justify-between"><span>BIC:</span> <strong>{sysPrices.schoolBic}</strong></p>
                  </div>
                  <div className="mt-4 pt-4 border-t border-amber-200">
                    <p className="text-xs text-amber-700 uppercase tracking-wider font-sans font-bold mb-1">Verwendungszweck (SEHR WICHTIG):</p>
                    <p className="font-bold text-2xl bg-white px-3 py-3 rounded-lg border-2 border-amber-300 text-center select-all shadow-sm tracking-widest">
                      MENSA {paymentPin}
                    </p>
                  </div>
                </div>
              )}

              <div className="bg-blue-50 text-blue-800 p-5 rounded-xl text-left border border-blue-100 text-sm mt-4">
                <p className="mb-3">Das Schülerprofil wurde erfolgreich im System registriert und die Pfandgebühr {orderCardPayment !== 'ueberweisung' && 'beglichen.'}</p>
                <p className="font-bold flex items-center gap-2"><CreditCard size={18}/> Wichtig für den nächsten Schritt:</p>
                <p className="mt-1">Die physische Chipkarte muss noch ausgegeben werden. Bitte wende dich (oder der Schüler) an eine <strong>Lehrkraft in der Schule</strong>, um eine freie Karte zu erhalten. Erst danach ist die Karte vollständig in der Verwaltung sichtbar und nutzbar.</p>
              </div>
              <button 
                onClick={() => setIsOrderCardOpen(false)}
                className="w-full mt-6 py-4 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold shadow-sm transition-colors"
              >
                Verstanden, Fenster schließen
              </button>
            </div>
          )}
        </Modal>

        {/* Lost Card Modal */}
        <Modal isOpen={isLostCardOpen} onClose={() => setIsLostCardOpen(false)} title={`Karte verwalten: ${lostCardData.card?.student || ''}`}>
          {lostCardStep === 'choose' && (
            <div className="space-y-4 animate-in fade-in duration-300">
              <p className="text-sm text-slate-600 mb-6">Wie möchtest du vorgehen? Wenn du die Karte nur verlegt hast, kannst du sie temporär sperren. Andernfalls kannst du direkt eine Ersatzkarte beantragen.</p>
              
              <button onClick={() => setLostCardStep('confirm-block')} className="w-full flex flex-col p-4 border border-slate-200 rounded-xl hover:border-amber-500 hover:bg-amber-50 transition-all text-left">
                <span className="font-bold text-slate-800 flex items-center gap-2"><AlertCircle size={18} className="text-amber-500"/> Nur sperren</span>
                <span className="text-sm text-slate-500 mt-1">Die Karte wird deaktiviert, kann aber später ggf. durch den Support reaktiviert werden. Kostenlos.</span>
              </button>

              <button onClick={() => setLostCardStep('reorder-checkout')} className="w-full flex flex-col p-4 border border-slate-200 rounded-xl hover:border-blue-500 hover:bg-blue-50 transition-all text-left mt-3">
                <span className="font-bold text-slate-800 flex items-center gap-2"><CreditCard size={18} className="text-blue-500"/> Sperren & Neu beantragen</span>
                <span className="text-sm text-slate-500 mt-1">Alte Karte wird dauerhaft gelöscht und eine Ersatzkarte hinterlegt. Kostenpflichtig ({sysPrices.cardDeposit.toFixed(2).replace('.', ',')} € Pfand).</span>
              </button>
            </div>
          )}
          {lostCardStep === 'confirm-block' && (
            <div className="space-y-4 animate-in fade-in duration-300">
              <div className="bg-amber-50 text-amber-800 p-5 rounded-xl border border-amber-200">
                <h4 className="font-bold mb-2 flex items-center gap-2"><AlertCircle size={20}/> Karte sperren</h4>
                <p className="text-sm">Bist du sicher, dass du die Karte von <strong>{lostCardData.card?.student}</strong> sperren möchtest? Sie kann danach nicht mehr für Zahlungen am Terminal genutzt werden.</p>
              </div>
              <div className="flex gap-3 mt-6">
                <button 
                  onClick={() => setIsLostCardOpen(false)}
                  className="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold transition-colors"
                >
                  Abbrechen
                </button>
                <button 
                  onClick={handleBlockCard}
                  disabled={isActionLoading}
                  className="flex-1 py-3 bg-amber-500 hover:bg-amber-600 text-white rounded-xl font-bold flex justify-center items-center gap-2 shadow-sm transition-colors disabled:opacity-50"
                >
                  {isActionLoading ? <Loader2 className="animate-spin" size={20} /> : 'Ja, jetzt sperren'}
                </button>
              </div>
            </div>
          )}
          {lostCardStep === 'reorder-checkout' && (
            <div className="space-y-4 animate-in fade-in slide-in-from-right-4 duration-300">
              <button onClick={() => setLostCardStep('choose')} className="flex items-center gap-2 text-sm text-slate-500 hover:text-slate-800 mb-4">
                <ChevronLeft size={16}/> Zurück
              </button>
              <div className="bg-slate-50 rounded-2xl p-6 border border-slate-100 mb-6">
                <div className="flex justify-between items-center mb-2 pb-2 border-b border-slate-200">
                  <div>
                    <p className="font-bold text-slate-800">Ersatzkarte</p>
                    <p className="text-sm text-slate-500">Für {lostCardData.card?.student}</p>
                  </div>
                  <span className="font-semibold">{sysPrices.cardDeposit.toFixed(2).replace('.', ',')} €</span>
                </div>
                
                {lostCardData.useBalance && user.balance > 0 && (
                  <>
                    <div className="flex justify-between items-center text-sm mt-2 text-slate-500">
                      <p>Abzüglich Guthaben</p>
                      <span>- {Math.min(sysPrices.cardDeposit, user.balance).toFixed(2).replace('.', ',')} €</span>
                    </div>
                  </>
                )}

                <div className="flex justify-between items-center text-lg mt-4 pt-4 border-t border-slate-200">
                  <p className="font-bold text-slate-800">Gesamtbetrag</p>
                  <span className="font-bold text-blue-600">{Math.max(0, sysPrices.cardDeposit - (lostCardData.useBalance ? user.balance : 0)).toFixed(2).replace('.', ',')} €</span>
                </div>
              </div>

              {user.balance > 0 && (
                <label className="flex items-center gap-3 cursor-pointer mb-6 p-3 border border-slate-200 rounded-xl bg-slate-50 hover:bg-slate-100 transition-colors">
                  <input 
                    type="checkbox" 
                    checked={lostCardData.useBalance} 
                    onChange={(e) => setLostCardData({...lostCardData, useBalance: e.target.checked})}
                    className="w-5 h-5 text-blue-600 rounded border-slate-300 focus:ring-blue-500"
                  />
                  <div>
                    <p className="font-semibold text-slate-800 text-sm">Guthaben ({user.balance.toFixed(2).replace('.', ',')} €) nutzen</p>
                  </div>
                </label>
              )}

              {Math.max(0, sysPrices.cardDeposit - (lostCardData.useBalance ? user.balance : 0)) > 0 && (
                <>
                  <p className="font-semibold text-slate-800 text-sm mb-2">Zahlungsmethode wählen</p>
                  <div className="space-y-2 mb-6">
                    {[
                      { id: 'paypal', name: 'PayPal', icon: Smartphone },
                      { id: 'klarna', name: 'Klarna', icon: ShoppingCart },
                      { id: 'ueberweisung', name: 'Banküberweisung', icon: Landmark }
                    ].map(method => (
                      <label key={method.id} className={`flex items-center gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all ${lostCardData.paymentMethod === method.id ? 'border-blue-500 bg-blue-50' : 'border-slate-200 hover:border-blue-300'}`}>
                        <input type="radio" checked={lostCardData.paymentMethod === method.id} onChange={() => setLostCardData({...lostCardData, paymentMethod: method.id})} className="w-5 h-5 text-blue-600" />
                        <method.icon size={20} className={lostCardData.paymentMethod === method.id ? 'text-blue-600' : 'text-slate-500'} />
                        <span className="font-bold text-slate-800">{method.name}</span>
                      </label>
                    ))}
                  </div>
                </>
              )}

              <CheckoutAction 
                amount={Math.max(0, sysPrices.cardDeposit - (lostCardData.useBalance ? user.balance : 0))}
                paymentMethod={lostCardData.paymentMethod}
                actionType="reorder_card"
                actionData={{ holderId: lostCardData.card?.holderId, useBalance: lostCardData.useBalance }}
                onManualSubmit={(method) => handleManualReorderCard(method)}
                onSucceed={() => {
                  setLostCardStep('success-reorder');
                  fetchUserData(false);
                }}
                onProcessing={() => setIsActionLoading(true)}
                onFinished={() => setIsActionLoading(false)}
                isLoading={isActionLoading}
                user={user}
                config={sysPrices}
              />
            </div>
          )}
          {lostCardStep === 'success-block' && (
            <div className="text-center space-y-4 py-4 animate-in fade-in duration-300">
              <div className="w-20 h-20 bg-amber-100 text-amber-500 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                <AlertCircle size={40} />
              </div>
              <h3 className="text-2xl font-bold text-slate-800">Karte gesperrt</h3>
              <p className="text-slate-600">Die Karte wurde deaktiviert und kann nicht mehr am Terminal verwendet werden.</p>
              <button 
                onClick={() => setIsLostCardOpen(false)}
                className="w-full mt-6 py-4 bg-slate-800 hover:bg-slate-900 text-white rounded-xl font-bold shadow-sm transition-colors"
              >
                Schließen
              </button>
            </div>
          )}
          {lostCardStep === 'success-reorder' && (
            <div className="text-center space-y-4 py-4 animate-in fade-in duration-300">
              <div className="w-20 h-20 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                <CheckCircle2 size={40} />
              </div>
              <h3 className="text-2xl font-bold text-slate-800">Ersatzkarte beantragt</h3>

              {lostCardData.paymentMethod === 'ueberweisung' && paymentPin && (
                <div className="bg-amber-50 text-amber-900 p-5 rounded-xl text-sm border border-amber-200 text-left mb-6">
                  <p className="font-bold mb-3">Zahlung ausstehend. Bitte überweise den fälligen Betrag an:</p>
                  <div className="space-y-2 font-mono">
                    <p className="flex justify-between"><span>Empfänger:</span> <strong>{sysPrices.schoolName}</strong></p>
                          <p className="flex justify-between"><span>IBAN:</span> <strong>{sysPrices.schoolIban}</strong></p>
                          <p className="flex justify-between"><span>BIC:</span> <strong>{sysPrices.schoolBic}</strong></p>
                  </div>
                  <div className="mt-4 pt-4 border-t border-amber-200">
                    <p className="text-xs text-amber-700 uppercase tracking-wider font-sans font-bold mb-1">Verwendungszweck (SEHR WICHTIG):</p>
                    <p className="font-bold text-2xl bg-white px-3 py-3 rounded-lg border-2 border-amber-300 text-center select-all shadow-sm tracking-widest">
                      MENSA {paymentPin}
                    </p>
                  </div>
                </div>
              )}

              <div className="bg-blue-50 text-blue-800 p-5 rounded-xl text-left border border-blue-100 text-sm mt-4">
                <p className="mb-3">Die alte Karte wurde gelöscht und der Antrag für eine neue Karte erfolgreich hinterlegt.</p>
                <p className="font-bold flex items-center gap-2"><CreditCard size={18}/> Wichtig für die Ausgabe:</p>
                <p className="mt-1">Wie bei der Ersteinrichtung muss die physische Ersatzkarte erst von einer <strong>Lehrkraft</strong> an den Schüler herausgegeben werden. Danach ist sie wieder in deinem Account verknüpft.</p>
              </div>
              <button 
                onClick={() => setIsLostCardOpen(false)}
                className="w-full mt-6 py-4 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold shadow-sm transition-colors"
              >
                Verstanden, Fenster schließen
              </button>
            </div>
          )}
        </Modal>

        {/* Global Toast Notification */}
        {isToastOpen && (
          <div className="fixed bottom-24 md:bottom-8 left-1/2 -translate-x-1/2 bg-slate-800 text-white px-6 py-3 rounded-full shadow-xl flex items-center gap-2 z-50 animate-in slide-in-from-bottom-4 fade-in duration-300">
            <CheckCircle2 size={18} className="text-green-400" />
            <span className="text-sm font-medium">{toastMessage}</span>
          </div>
        )}
      </div>
  );
}