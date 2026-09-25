# Scanner

Dans les établissements scolaires en France (écoles primaires, collèges, lycées), le matériel reprographique est principalement acquis via la centrale d'achat public **UGAP** ou par des marchés publics locaux des collectivités territoriales (communes, départements, régions).

## Marques et modèles les plus présents

- **Toshiba Tec :** Titulaire historique des marchés publics UGAP (très représenté avec la gamme **e-STUDIO**, ex. e-STUDIO 2021AC, 2525AC, e-339CS).
- **Canon :** Une des marques les plus déployées dans l'enseignement (gammes **imageRUNNER ADVANCE DX**, ex. C3525i, C3530i).
- **Ricoh :** Très présent dans les établissements du secondaire et du supérieur (gammes **IM C** et **MP C**).
- **Konica Minolta :** Très répandu dans les collectivités et écoles (gamme **bizhub**, ex. C258, C227, C368).
- **Kyocera** et **Sharp :** Également présents via des groupements d'achats régionaux.

## Interfaces pour récupérer les fichiers numérisés

Les photocopieurs/scanneurs d'entreprise (MFP) équipés de chargeurs de documents (ADF) fonctionnent quasi exclusivement en **mode Push** : l'utilisateur pose sa pile de feuilles, appuie sur le bouton du copieur, et la machine expédie le fichier numérisé (PDF multipage, TIFF ou JPEG) sur le réseau.

### SMTP (Scan-to-Email)

- *Fonctionnement :* Le scan est envoyé en pièce jointe d'un e-mail.
- *Côté programme :* Ton programme consulte une boîte e-mail dédiée (via IMAP/POP3) ou agit comme un serveur SMTP récepteur.
Disponibilité : Fonctionnalité standard intégrée de série sur la quasi-totalité des copieurs multifonctions professionnels depuis plus de 20 ans.

### FTP / FTPS

- *Fonctionnement :* Le copieur se connecte à un serveur FTP pour y déposer les fichiers.
- *Côté programme :* Tu peux embarquer un mini-serveur FTP au sein de ton application (ex. via `pyftpdlib` en Python) pour recevoir les scans directement en mémoire ou sur disque sans dépendre des droits SMB du système.
Disponibilité : Intégré de série sur pratiquement toutes les machines professionnelles, au même titre que le protocole SMB (dossier partagé).


### WebDAV / HTTP POST

- *Fonctionnement :* Les copieurs récents permettent l'envoi direct vers un endpoint HTTP/HTTPS ou un dossier WebDAV.
Disponibilité : Intégré sur la plupart des gammes récentes (sorties depuis 5 à 10 ans), mais souvent absent ou nécessitant des licences/extensions logicielles sur les modèles d'entrée de gamme ou plus anciens.

## Qualité et poids des images

### 1. Tableau récapitulatif des dimensions

| Résolution | Dimensions (Pixels) | Définition | Poids estimé par page |
| :--- | :--- | :--- | :--- |
| **200 DPI** *(Standard "Normal / Mail")* | **$1654 x 2339$ px** | ~3,9 Mpx | $50 Ko à $1 Mo |
| **300 DPI** *(Standard "Haute Qualité / Photo")* | **$2480 x 3508$ px** | ~8,7 Mpx | $300 Ko à $3 Mo |

---

### 2. Calcul mathématique des dimensions

Les dimensions standard d'une feuille au format **A4** sont de **$21 x 29{,}7cm **, ce qui équivaut à environ **$8{,}27 x 11{,}69\text{ pouces}$**.

La formule de calcul du nombre de pixels est la suivante :
$$\text{Pixels} = \text{Dimension en pouces} x \text{DPI}$$

### À 200 DPI (Mode Normal usuel)
* **Largeur :** $8{,}27 x 200 ~ 1654\text{ pixels}$
* **Hauteur :** $11{,}69 x 200 ~ 2339\text{ pixels}$

### À 300 DPI (Mode Supérieur)
* **Largeur :** $8{,}27 x 300 ~ 2480\text{ pixels}$
* **Hauteur :** $11{,}69 x 300 ~ 3508\text{ pixels}$

---

### 3. Facteurs influençant le poids du fichier

Le poids final du fichier numérisé dépend principalement des options sélectionnées sur le panneau de commande :

1. **Le mode de couleur :**
   * **Noir & Blanc (Texte seul) :** Extrêmement léger (souvent $< 100 Ko par page en PDF).
   * **Niveau de gris :** Poids intermédiaire ($200$ à $500 Ko par page).
   * **Couleur :** Plus lourd ($1$ à $3 Mo par page).

2. **Le mode d'envoi (Scan-to-Email vs Scan-to-Folder) :**
   * Lorsque le scan est envoyé directement par **e-mail** depuis la machine, le profil « Normal » est presque toujours verrouillé à **200 DPI** pour éviter le blocage des serveurs de messagerie de l'Éducation Nationale ou des collectivités.
